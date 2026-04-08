<?php

/*
 * AiServiceFactoryTest.php
 *
 * Unit tests for the AI assistant service factory.
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
use App\Plugins\AiAssistant\Services\AiServiceFactory;
use App\Plugins\AiAssistant\Services\CostTracker;
use LibreNMS\Tests\TestCase;

class AiServiceFactoryTest extends TestCase
{
    public function testIsConfiguredReturnsFalseWithEmptySettings(): void
    {
        $this->assertFalse(AiServiceFactory::isConfigured([]));
    }

    public function testIsConfiguredReturnsFalseWithEmptyApiKey(): void
    {
        $this->assertFalse(AiServiceFactory::isConfigured(['api_key' => '']));
    }

    public function testIsConfiguredReturnsTrueWithApiKey(): void
    {
        $this->assertTrue(AiServiceFactory::isConfigured(['api_key' => 'sk-test-key']));
    }

    public function testBuildProviderReturnsOpenAiCompatibleProvider(): void
    {
        $provider = AiServiceFactory::buildProvider([
            'api_url' => 'https://api.example.com/v1',
            'api_key' => 'sk-test',
            'model' => 'gpt-4o-mini',
        ]);

        $this->assertInstanceOf(OpenAiCompatibleProvider::class, $provider);
        $this->assertEquals('gpt-4o-mini', $provider->getModel());
    }

    public function testBuildProviderUsesDefaults(): void
    {
        $provider = AiServiceFactory::buildProvider([]);

        $this->assertInstanceOf(OpenAiCompatibleProvider::class, $provider);
        $this->assertEquals('gpt-4o', $provider->getModel());
    }

    public function testBuildCostTrackerReturnsCostTracker(): void
    {
        $tracker = AiServiceFactory::buildCostTracker([
            'max_cost_daily' => 5.0,
            'max_cost_monthly' => 50.0,
        ]);

        $this->assertIsObject($tracker);
    }
}
