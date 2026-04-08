<?php

/*
 * ToolsTest.php
 *
 * Unit tests for AiAssistant tool interface and tool classes.
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

use App\Plugins\AiAssistant\Tools\AbstractAiTool;
use App\Plugins\AiAssistant\Tools\AiToolInterface;
use App\Plugins\AiAssistant\Tools\GetActiveAlerts;
use App\Plugins\AiAssistant\Tools\GetAlertHistory;
use App\Plugins\AiAssistant\Tools\GetDeviceDetail;
use App\Plugins\AiAssistant\Tools\GetDeviceOutages;
use App\Plugins\AiAssistant\Tools\GetDevices;
use App\Plugins\AiAssistant\Tools\GetEventLog;
use App\Plugins\AiAssistant\Tools\GetNetworkSummary;
use App\Plugins\AiAssistant\Tools\GetPorts;
use App\Plugins\AiAssistant\Tools\GetRouting;
use App\Plugins\AiAssistant\Tools\GetSensors;
use App\Plugins\AiAssistant\Tools\GetServices;
use App\Plugins\AiAssistant\Tools\GetSyslog;
use LibreNMS\Tests\TestCase;

class ToolsTest extends TestCase
{
    /**
     * @return list<AiToolInterface>
     */
    private function allTools(): array
    {
        return [
            new GetNetworkSummary(),
            new GetDevices(),
            new GetDeviceDetail(),
            new GetActiveAlerts(),
            new GetAlertHistory(),
            new GetPorts(),
            new GetSensors(),
            new GetEventLog(),
            new GetSyslog(),
            new GetDeviceOutages(),
            new GetServices(),
            new GetRouting(),
        ];
    }

    public function testAllToolsImplementInterface(): void
    {
        foreach ($this->allTools() as $tool) {
            $this->assertInstanceOf(AiToolInterface::class, $tool, $tool::class . ' must implement AiToolInterface');
            $this->assertInstanceOf(AbstractAiTool::class, $tool, $tool::class . ' must extend AbstractAiTool');
        }
    }

    public function testAllToolsHaveUniqueNames(): void
    {
        $names = array_map(fn ($t) => $t->name(), $this->allTools());
        $unique = array_unique($names);
        $this->assertCount(count($names), $unique, 'All tool names must be unique. Duplicates: ' . implode(', ', array_diff_assoc($names, $unique)));
    }

    public function testAllToolNamesAreSnakeCase(): void
    {
        foreach ($this->allTools() as $tool) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]+$/', $tool->name(), $tool->name() . ' must be snake_case');
        }
    }

    public function testAllToolsHaveNonEmptyDescription(): void
    {
        foreach ($this->allTools() as $tool) {
            $this->assertNotEmpty($tool->description(), $tool::class . ' must have a non-empty description');
        }
    }

    public function testToFunctionDefinitionReturnsCorrectStructure(): void
    {
        $tool = new GetNetworkSummary();
        $def = $tool->toFunctionDefinition();

        $this->assertArrayHasKey('type', $def);
        $this->assertSame('function', $def['type']);
        $this->assertArrayHasKey('function', $def);
        $this->assertArrayHasKey('name', $def['function']);
        $this->assertArrayHasKey('description', $def['function']);
        $this->assertArrayHasKey('parameters', $def['function']);
        $this->assertSame('get_network_summary', $def['function']['name']);
        $this->assertSame($tool->description(), $def['function']['description']);
    }

    public function testToFunctionDefinitionMatchesNameAndDescription(): void
    {
        foreach ($this->allTools() as $tool) {
            $def = $tool->toFunctionDefinition();
            $this->assertSame($tool->name(), $def['function']['name'], $tool::class . ' function name mismatch');
            $this->assertSame($tool->description(), $def['function']['description'], $tool::class . ' description mismatch');
        }
    }

    public function testGetNetworkSummaryParametersAreEmpty(): void
    {
        $tool = new GetNetworkSummary();
        $params = $tool->parameters();
        $this->assertSame('object', $params['type']);
    }

    public function testGetDevicesParametersHaveStatusEnum(): void
    {
        $tool = new GetDevices();
        $params = $tool->parameters();
        $this->assertArrayHasKey('properties', $params);
        $this->assertArrayHasKey('status', $params['properties']);
        $this->assertSame(['up', 'down', 'all'], $params['properties']['status']['enum']);
    }

    public function testGetActiveAlertsParametersHaveSeverityEnum(): void
    {
        $tool = new GetActiveAlerts();
        $params = $tool->parameters();
        $this->assertArrayHasKey('severity', $params['properties']);
        $this->assertContains('critical', $params['properties']['severity']['enum']);
    }

    public function testGetRoutingParametersHaveStatusEnum(): void
    {
        $tool = new GetRouting();
        $params = $tool->parameters();
        $this->assertArrayHasKey('status', $params['properties']);
        $statusEnum = $params['properties']['status']['enum'];
        $this->assertContains('established', $statusEnum);
        $this->assertContains('down', $statusEnum);
        $this->assertContains('all', $statusEnum);
    }

    public function testExpectedToolCount(): void
    {
        $this->assertCount(12, $this->allTools(), 'There should be exactly 12 tools');
    }
}
