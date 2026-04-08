<?php

/*
 * LlmServiceTest.php
 *
 * Unit tests for the AI assistant LlmService.
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

namespace LibreNMS\Tests\Unit\AiAssistant;

use App\Plugins\AiAssistant\Providers\LlmProviderInterface;
use App\Plugins\AiAssistant\Providers\LlmResponse;
use App\Plugins\AiAssistant\Services\ContextBuilder;
use App\Plugins\AiAssistant\Services\CostTracker;
use App\Plugins\AiAssistant\Services\LlmService;
use App\Plugins\AiAssistant\Tools\AiToolInterface;
use LibreNMS\Tests\TestCase;
use Mockery;

class LlmServiceTest extends TestCase
{
    private function makeMockProvider(array $responses): LlmProviderInterface
    {
        $provider = Mockery::mock(LlmProviderInterface::class);
        $callIndex = 0;

        $provider->shouldReceive('chat')
            ->andReturnUsing(function () use (&$callIndex, $responses) {
                return $responses[$callIndex++] ?? $responses[count($responses) - 1];
            });

        $provider->shouldReceive('getModel')->andReturn('test-model');
        $provider->shouldReceive('getMaxContextTokens')->andReturn(128000);

        return $provider;
    }

    private function makeMockContextBuilder(): ContextBuilder
    {
        $builder = Mockery::mock(ContextBuilder::class);
        $builder->shouldReceive('buildContextSnapshot')
            ->andReturn("Network Status: 10 devices (10 up, 0 down)\nActive Alerts: 0");

        return $builder;
    }

    private function makeMockCostTracker(): CostTracker
    {
        $tracker = Mockery::mock(CostTracker::class);
        $tracker->shouldReceive('checkBudget')->andReturn(true);
        $tracker->shouldReceive('calculateCost')->andReturn(0.001);
        $tracker->shouldReceive('recordCost');
        $tracker->shouldReceive('checkQueryBudget')->andReturn(true);

        return $tracker;
    }

    public function testSimpleTextResponse(): void
    {
        $provider = $this->makeMockProvider([
            new LlmResponse(
                content: 'The network is healthy with 10 devices online.',
                toolCalls: [],
                inputTokens: 100,
                outputTokens: 20,
                stopReason: 'end',
            ),
        ]);

        $service = new LlmService(
            $provider,
            $this->makeMockContextBuilder(),
            $this->makeMockCostTracker(),
        );

        $result = $service->query(
            [['role' => 'user', 'content' => 'How is the network?']],
            null,
            'chat',
        );

        $this->assertEquals('The network is healthy with 10 devices online.', $result['content']);
        $this->assertEmpty($result['tool_calls_made']);
        $this->assertGreaterThan(0, $result['total_tokens']);
    }

    public function testToolCallingLoop(): void
    {
        $provider = $this->makeMockProvider([
            // First call: LLM requests a tool call
            new LlmResponse(
                content: '',
                toolCalls: [
                    [
                        'id' => 'call_1',
                        'function' => [
                            'name' => 'test_tool',
                            'arguments' => '{}',
                        ],
                    ],
                ],
                inputTokens: 100,
                outputTokens: 30,
                stopReason: 'tool_use',
            ),
            // Second call: LLM returns final response
            new LlmResponse(
                content: 'Based on the tool results, everything looks good.',
                toolCalls: [],
                inputTokens: 200,
                outputTokens: 25,
                stopReason: 'end',
            ),
        ]);

        $tool = Mockery::mock(AiToolInterface::class);
        $tool->shouldReceive('name')->andReturn('test_tool');
        $tool->shouldReceive('description')->andReturn('A test tool');
        $tool->shouldReceive('parameters')->andReturn(['type' => 'object', 'properties' => new \stdClass]);
        $tool->shouldReceive('toFunctionDefinition')->andReturn([
            'type' => 'function',
            'function' => [
                'name' => 'test_tool',
                'description' => 'A test tool',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass],
            ],
        ]);
        $tool->shouldReceive('execute')->andReturn(['status' => 'ok', 'data' => 'test']);

        $service = new LlmService(
            $provider,
            $this->makeMockContextBuilder(),
            $this->makeMockCostTracker(),
            [$tool],
        );

        $result = $service->query(
            [['role' => 'user', 'content' => 'Run the test tool']],
            null,
            'chat',
        );

        $this->assertEquals('Based on the tool results, everything looks good.', $result['content']);
        $this->assertContains('test_tool', $result['tool_calls_made']);
    }

    public function testBudgetExceededReturnsMessage(): void
    {
        $provider = Mockery::mock(LlmProviderInterface::class);
        $provider->shouldReceive('getModel')->andReturn('test-model');

        $costTracker = Mockery::mock(CostTracker::class);
        $costTracker->shouldReceive('checkBudget')->andReturn(false);

        $service = new LlmService(
            $provider,
            $this->makeMockContextBuilder(),
            $costTracker,
        );

        $result = $service->query(
            [['role' => 'user', 'content' => 'Hello']],
            null,
            'chat',
        );

        $this->assertStringContainsString('budget', $result['content']);
        $this->assertEquals(0, $result['total_tokens']);
        $this->assertEquals(0.0, $result['cost']);
    }

    public function testMaxIterationsReturnsLimitMessage(): void
    {
        // Create a response that always requests a tool call (will hit max iterations)
        $toolCallResponse = new LlmResponse(
            content: '',
            toolCalls: [
                [
                    'id' => 'call_loop',
                    'function' => [
                        'name' => 'test_tool',
                        'arguments' => '{}',
                    ],
                ],
            ],
            inputTokens: 50,
            outputTokens: 10,
            stopReason: 'tool_use',
        );

        $responses = array_fill(0, 15, $toolCallResponse);
        $provider = $this->makeMockProvider($responses);

        $tool = Mockery::mock(AiToolInterface::class);
        $tool->shouldReceive('name')->andReturn('test_tool');
        $tool->shouldReceive('toFunctionDefinition')->andReturn([
            'type' => 'function',
            'function' => ['name' => 'test_tool', 'description' => 'test', 'parameters' => ['type' => 'object']],
        ]);
        $tool->shouldReceive('execute')->andReturn(['data' => 'looping']);

        $service = new LlmService(
            $provider,
            $this->makeMockContextBuilder(),
            $this->makeMockCostTracker(),
            [$tool],
        );

        $result = $service->query(
            [['role' => 'user', 'content' => 'Loop forever']],
            null,
            'chat',
        );

        $this->assertStringContainsString('maximum number of tool-calling steps', $result['content']);
        $this->assertCount(10, $result['tool_calls_made']);
    }

    public function testDiscoverToolsReturnsArray(): void
    {
        $tools = LlmService::discoverTools();

        $this->assertIsArray($tools);
        // All returned items should implement AiToolInterface
        foreach ($tools as $tool) {
            $this->assertInstanceOf(AiToolInterface::class, $tool);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
