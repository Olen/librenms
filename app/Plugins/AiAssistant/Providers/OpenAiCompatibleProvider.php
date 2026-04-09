<?php

/*
 * OpenAiCompatibleProvider.php
 *
 * LLM provider implementation for OpenAI-compatible chat completions APIs.
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

namespace App\Plugins\AiAssistant\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use LibreNMS\Util\Http;

class OpenAiCompatibleProvider implements LlmProviderInterface
{
    private readonly PendingRequest $client;

    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens = 1000,
        private readonly float $temperature = 0.3,
        private readonly int $maxContextTokens = 128000,
        ?PendingRequest $httpClient = null,
    ) {
        $this->client = $httpClient ?? Http::client()
            ->baseUrl($this->apiUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson();
    }

    public function chat(array $messages, array $tools = []): LlmResponse
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
        ];

        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        $response = $this->client->post('/chat/completions', $payload);

        if ($response->failed()) {
            $status = $response->status();
            // Log the full provider error body server-side for debugging,
            // but never include it in the thrown exception. Provider
            // responses can contain echoed-back request data, API
            // endpoint details, or other context that bubbles up through
            // logs and error pages if the exception is rendered.
            Log::warning('LLM provider request failed', [
                'status' => $status,
                'error' => $response->json('error.message') ?? $response->body(),
            ]);

            throw new \RuntimeException("LLM API error (HTTP {$status})", $status);
        }

        $data = $response->json();
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $content = $message['content'] ?? '';
        $toolCalls = $message['tool_calls'] ?? [];
        $inputTokens = $data['usage']['prompt_tokens'] ?? 0;
        $outputTokens = $data['usage']['completion_tokens'] ?? 0;

        $finishReason = $choice['finish_reason'] ?? '';
        $stopReason = match ($finishReason) {
            'tool_calls' => 'tool_use',
            'length' => 'max_tokens',
            default => 'end',
        };

        return new LlmResponse(
            content: $content,
            toolCalls: $toolCalls,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            stopReason: $stopReason,
        );
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getMaxContextTokens(): int
    {
        return $this->maxContextTokens;
    }
}
