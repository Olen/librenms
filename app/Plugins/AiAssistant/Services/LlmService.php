<?php

/*
 * LlmService.php
 *
 * Core service for interacting with LLM providers, handling tool-calling loops
 * and cost tracking for the AI Assistant plugin.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2026 LibreNMS
 * @author     LibreNMS Contributors
 */

namespace App\Plugins\AiAssistant\Services;

use App\Models\User;
use App\Plugins\AiAssistant\Providers\LlmProviderInterface;
use App\Plugins\AiAssistant\Providers\LlmResponse;
use App\Plugins\AiAssistant\Tools\AbstractAiTool;
use App\Plugins\AiAssistant\Tools\AiToolInterface;
use Illuminate\Support\Facades\Log;

class LlmService
{
    private const MAX_TOOL_ITERATIONS = 10;

    private const MAX_RETRIES = 3;

    /** @var array<string, AiToolInterface> */
    private array $toolMap = [];

    /**
     * @param  AiToolInterface[]  $tools
     */
    public function __construct(
        private LlmProviderInterface $provider,
        private ContextBuilder $contextBuilder,
        private CostTracker $costTracker,
        array $tools = [],
    ) {
        foreach ($tools as $tool) {
            $this->toolMap[$tool->name()] = $tool;
        }
    }

    /**
     * Send a query to the LLM, handling tool-calling loops and cost tracking.
     *
     * @param  array  $messages  Conversation messages [{role, content, ...}]
     * @param  User|null  $user  Current user for RBAC
     * @param  string  $context  Cost context: 'chat', 'monitoring', 'report'
     * @param  callable|null  $statusCallback  Called with status updates during tool execution
     * @return array{content: string, tool_calls_made: string[], total_tokens: int, cost: float}
     */
    public function query(array $messages, ?User $user, string $context, ?callable $statusCallback = null): array
    {
        // Check budget before proceeding
        if (! $this->costTracker->checkBudget()) {
            return [
                'content' => 'I am currently unable to process requests because the AI usage budget has been reached. Please contact an administrator.',
                'tool_calls_made' => [],
                'total_tokens' => 0,
                'cost' => 0.0,
            ];
        }

        // Build system prompt
        $networkSnapshot = $this->contextBuilder->buildContextSnapshot($user);
        $systemPrompt = $this->buildSystemPrompt($networkSnapshot, $user);

        // Prepend system message
        array_unshift($messages, [
            'role' => 'system',
            'content' => $systemPrompt,
        ]);

        // Get tool definitions
        $toolDefinitions = array_map(
            fn (AiToolInterface $tool) => $tool->toFunctionDefinition(),
            array_values($this->toolMap)
        );

        $toolCallsMade = [];
        $totalTokens = 0;
        $totalCost = 0.0;

        // Tool-calling loop
        for ($iteration = 0; $iteration < self::MAX_TOOL_ITERATIONS; $iteration++) {
            $response = $this->callWithRetry($messages, $toolDefinitions);

            // Track cost
            $iterationCost = $this->costTracker->calculateCost($response);
            $totalCost += $iterationCost;
            $totalTokens += $response->totalTokens();

            $this->costTracker->recordCost(
                $response,
                $context,
                $this->provider->getModel(),
                $this->provider->getModel(),
                $user?->user_id
            );

            // No tool calls - return the content
            if (! $response->hasToolCalls()) {
                return [
                    'content' => $response->content,
                    'tool_calls_made' => $toolCallsMade,
                    'total_tokens' => $totalTokens,
                    'cost' => $totalCost,
                ];
            }

            // Check per-query budget before executing tools
            if (! $this->costTracker->checkQueryBudget($totalCost)) {
                return [
                    'content' => 'I have reached the per-query cost limit while processing your request. Here is what I have so far: ' . $response->content,
                    'tool_calls_made' => $toolCallsMade,
                    'total_tokens' => $totalTokens,
                    'cost' => $totalCost,
                ];
            }

            // Append assistant message with tool calls
            $messages[] = [
                'role' => 'assistant',
                'content' => $response->content,
                'tool_calls' => $response->toolCalls,
            ];

            // Execute each tool call
            foreach ($response->toolCalls as $toolCall) {
                $toolName = $toolCall['function']['name'] ?? 'unknown';
                $toolCallsMade[] = $toolName;

                if ($statusCallback) {
                    $statusCallback("Calling tool: {$toolName}");
                }

                $result = $this->executeTool($toolName, $toolCall['function']['arguments'] ?? '{}', $user);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'] ?? '',
                    'content' => json_encode($result),
                ];
            }
        }

        // Max iterations reached
        return [
            'content' => 'I have reached the maximum number of tool-calling steps. Here is what I have gathered so far. Please try a more specific question.',
            'tool_calls_made' => $toolCallsMade,
            'total_tokens' => $totalTokens,
            'cost' => $totalCost,
        ];
    }

    /**
     * Call the LLM provider with retry logic for transient errors.
     */
    private function callWithRetry(array $messages, array $tools): LlmResponse
    {
        $lastException = null;

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            try {
                return $this->provider->chat($messages, $tools);
            } catch (\Exception $e) {
                $lastException = $e;
                $code = $e->getCode();

                // Only retry on 429 (rate limit) or 5xx (server errors)
                if ($code !== 429 && ($code < 500 || $code > 599)) {
                    throw $e;
                }

                // Exponential backoff: 1s, 2s, 4s
                if ($attempt < self::MAX_RETRIES - 1) {
                    usleep((int) (pow(2, $attempt) * 1000000));
                }
            }
        }

        throw $lastException;
    }

    /**
     * Execute a tool by name with the given parameters.
     */
    private function executeTool(string $name, string $argumentsJson, ?User $user): array
    {
        if (! isset($this->toolMap[$name])) {
            return ['error' => "Unknown tool: {$name}"];
        }

        try {
            $params = json_decode($argumentsJson, true) ?? [];

            return $this->toolMap[$name]->execute($params, $user);
        } catch (\Exception $e) {
            Log::warning("AI tool execution failed: {$name}", [
                'error' => $e->getMessage(),
            ]);

            return ['error' => "Tool execution failed: {$e->getMessage()}"];
        }
    }

    /**
     * Build the system prompt with identity, network snapshot, and user scope.
     */
    private function buildSystemPrompt(string $networkSnapshot, ?User $user): string
    {
        $prompt = "You are the LibreNMS AI Assistant, a helpful network monitoring expert. ";
        $prompt .= "You help users understand their network status, investigate issues, and manage their monitoring system.\n\n";
        $prompt .= "Current Network Context:\n{$networkSnapshot}\n\n";
        $prompt .= "Guidelines:\n";
        $prompt .= "- Be concise and technical when appropriate\n";
        $prompt .= "- Use the available tools to look up real-time data rather than guessing\n";
        $prompt .= "- When reporting device or alert information, include relevant details like hostnames and timestamps\n";
        $prompt .= "- If you are unsure about something, say so rather than making assumptions\n";

        if ($user) {
            $prompt .= "\nUser: {$user->username}";
            if ($user->realname) {
                $prompt .= " ({$user->realname})";
            }
            $prompt .= "\nNote: Query results are automatically filtered based on this user's access permissions.";
        }

        return $prompt;
    }

    /**
     * Discover and instantiate all tool classes from the Tools directory.
     *
     * @return AiToolInterface[]
     */
    public static function discoverTools(): array
    {
        $tools = [];
        $toolsDir = __DIR__ . '/../Tools';
        $files = glob($toolsDir . '/*.php');

        if ($files === false) {
            return $tools;
        }

        foreach ($files as $file) {
            $className = pathinfo($file, PATHINFO_FILENAME);

            // Skip the interface and abstract base class
            if ($className === 'AiToolInterface' || $className === 'AbstractAiTool') {
                continue;
            }

            $fqcn = 'App\\Plugins\\AiAssistant\\Tools\\' . $className;

            if (! class_exists($fqcn)) {
                continue;
            }

            $reflection = new \ReflectionClass($fqcn);
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            if ($reflection->implementsInterface(AiToolInterface::class)) {
                $tools[] = new $fqcn();
            }
        }

        return $tools;
    }
}
