<?php

/*
 * LlmResponseTest.php
 *
 * Unit tests for LlmResponse value object.
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

use App\Plugins\AiAssistant\Providers\LlmResponse;
use LibreNMS\Tests\TestCase;

class LlmResponseTest extends TestCase
{
    public function testHasToolCallsReturnsTrueWhenPresent(): void
    {
        $response = new LlmResponse(
            content: '',
            toolCalls: [['id' => 'call_1', 'function' => ['name' => 'get_devices', 'arguments' => '{}']]],
            inputTokens: 100,
            outputTokens: 50,
            stopReason: 'tool_use',
        );
        $this->assertTrue($response->hasToolCalls());
    }

    public function testHasToolCallsReturnsFalseWhenEmpty(): void
    {
        $response = new LlmResponse(
            content: 'The network looks healthy.',
            toolCalls: [],
            inputTokens: 100,
            outputTokens: 50,
            stopReason: 'end',
        );
        $this->assertFalse($response->hasToolCalls());
    }

    public function testTotalTokens(): void
    {
        $response = new LlmResponse(
            content: 'test',
            toolCalls: [],
            inputTokens: 150,
            outputTokens: 75,
            stopReason: 'end',
        );
        $this->assertEquals(225, $response->totalTokens());
    }
}
