<?php

/*
 * CostTrackerTest.php
 *
 * Unit tests for the AI assistant CostTracker service.
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
use App\Plugins\AiAssistant\Services\CostTracker;
use LibreNMS\Tests\TestCase;

class CostTrackerTest extends TestCase
{
    private function makeTracker(
        float $inputCost = 0.00001,
        float $outputCost = 0.00003,
        float $dailyMax = 10.0,
        float $monthlyMax = 100.0,
        float $queryMax = 1.0,
    ): CostTracker {
        return new CostTracker($inputCost, $outputCost, $dailyMax, $monthlyMax, $queryMax);
    }

    private function makeResponse(int $inputTokens = 100, int $outputTokens = 50): LlmResponse
    {
        return new LlmResponse(
            content: 'test response',
            toolCalls: [],
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            stopReason: 'end',
        );
    }

    public function testCalculateCostBasicMath(): void
    {
        $tracker = $this->makeTracker(
            inputCost: 0.00001,
            outputCost: 0.00003,
        );
        $response = $this->makeResponse(inputTokens: 1000, outputTokens: 500);

        $cost = $tracker->calculateCost($response);

        // (1000 * 0.00001) + (500 * 0.00003) = 0.01 + 0.015 = 0.025
        $this->assertEqualsWithDelta(0.025, $cost, 0.0000001);
    }

    public function testCalculateCostZeroTokens(): void
    {
        $tracker = $this->makeTracker();
        $response = $this->makeResponse(inputTokens: 0, outputTokens: 0);

        $cost = $tracker->calculateCost($response);

        $this->assertEquals(0.0, $cost);
    }

    public function testCheckQueryBudgetWithinLimit(): void
    {
        $tracker = $this->makeTracker(queryMax: 1.0);

        $this->assertTrue($tracker->checkQueryBudget(0.5));
    }

    public function testCheckQueryBudgetExceeded(): void
    {
        $tracker = $this->makeTracker(queryMax: 1.0);

        $this->assertFalse($tracker->checkQueryBudget(1.0));
        $this->assertFalse($tracker->checkQueryBudget(1.5));
    }

    public function testCheckQueryBudgetAtExactLimit(): void
    {
        $tracker = $this->makeTracker(queryMax: 0.5);

        // At exact limit should return false (not strictly less than)
        $this->assertFalse($tracker->checkQueryBudget(0.5));
    }

    public function testCalculateCostWithLargeTokenCounts(): void
    {
        $tracker = $this->makeTracker(
            inputCost: 0.000003,
            outputCost: 0.000015,
        );
        $response = $this->makeResponse(inputTokens: 100000, outputTokens: 50000);

        $cost = $tracker->calculateCost($response);

        // (100000 * 0.000003) + (50000 * 0.000015) = 0.3 + 0.75 = 1.05
        $this->assertEqualsWithDelta(1.05, $cost, 0.0000001);
    }

    // Note: checkBudget(), getDailyCost(), getMonthlyCost(), isApproachingDailyLimit()
    // and recordCost() require database access and are covered by integration tests.
}
