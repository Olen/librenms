<?php

/*
 * OpenAiCompatibleProviderTest.php
 *
 * Unit tests for OpenAiCompatibleProvider using Laravel Http::fake().
 *
 * Note: The test spec called for Guzzle MockHandler, but guzzlehttp/guzzle
 * is not a dependency of this project. Laravel's Http::fake() is used
 * instead, which is the idiomatic approach for this codebase.
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

use App\Plugins\AiAssistant\Providers\OpenAiCompatibleProvider;
use Illuminate\Support\Facades\Http;
use LibreNMS\Tests\TestCase;

class OpenAiCompatibleProviderTest extends TestCase
{
    private function makeProvider(): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider(
            apiUrl: 'https://api.openai.com/v1',
            apiKey: 'test-key',
            model: 'gpt-4o',
        );
    }

    public function testChatReturnsTextResponse(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'The network looks healthy.',
                            'tool_calls' => null,
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 50,
                ],
            ], 200),
        ]);

        $provider = $this->makeProvider();
        $response = $provider->chat([['role' => 'user', 'content' => 'How is the network?']]);

        $this->assertEquals('The network looks healthy.', $response->content);
        $this->assertFalse($response->hasToolCalls());
        $this->assertEquals(100, $response->inputTokens);
        $this->assertEquals(50, $response->outputTokens);
        $this->assertEquals(150, $response->totalTokens());
        $this->assertEquals('end', $response->stopReason);
    }

    public function testChatReturnsToolCallResponse(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [
                                [
                                    'id' => 'call_abc123',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'get_devices',
                                        'arguments' => '{}',
                                    ],
                                ],
                            ],
                        ],
                        'finish_reason' => 'tool_calls',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 200,
                    'completion_tokens' => 30,
                ],
            ], 200),
        ]);

        $provider = $this->makeProvider();
        $response = $provider->chat([['role' => 'user', 'content' => 'List all devices']]);

        $this->assertTrue($response->hasToolCalls());
        $this->assertEquals('tool_use', $response->stopReason);
        $this->assertEquals('', $response->content);
        $this->assertCount(1, $response->toolCalls);
        $this->assertEquals('get_devices', $response->toolCalls[0]['function']['name']);
    }

    public function testChatThrowsOnApiError(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Rate limit exceeded',
                    'type' => 'requests',
                ],
            ], 429),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/429/');

        $provider = $this->makeProvider();
        $provider->chat([['role' => 'user', 'content' => 'Hello']]);
    }

    public function testGetModelReturnsConfiguredModel(): void
    {
        $provider = new OpenAiCompatibleProvider(
            apiUrl: 'https://api.openai.com/v1',
            apiKey: 'test-key',
            model: 'gpt-4o-mini',
        );

        $this->assertEquals('gpt-4o-mini', $provider->getModel());
    }
}
