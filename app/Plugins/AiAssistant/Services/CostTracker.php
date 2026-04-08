<?php

/*
 * CostTracker.php
 *
 * Tracks and enforces budget limits for AI assistant API usage.
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

use App\Plugins\AiAssistant\Models\AiCostLog;
use App\Plugins\AiAssistant\Providers\LlmResponse;
use Carbon\Carbon;

class CostTracker
{
    public function __construct(
        private float $costPerInputToken,
        private float $costPerOutputToken,
        private float $maxDailyCost,
        private float $maxMonthlyCost,
        private float $maxQueryCost,
    ) {
    }

    /**
     * Calculate the cost of an LLM response.
     */
    public function calculateCost(LlmResponse $response): float
    {
        return ($response->inputTokens * $this->costPerInputToken)
            + ($response->outputTokens * $this->costPerOutputToken);
    }

    /**
     * Record a cost entry in the database.
     */
    public function recordCost(LlmResponse $response, string $context, string $provider, string $model, ?int $userId): void
    {
        $cost = $this->calculateCost($response);

        AiCostLog::create([
            'user_id' => $userId,
            'context' => $context,
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost' => $cost,
        ]);
    }

    /**
     * Check whether the daily or monthly budget has been exceeded.
     *
     * @return bool true if within budget, false if exceeded
     */
    public function checkBudget(): bool
    {
        if ($this->getDailyCost() >= $this->maxDailyCost) {
            return false;
        }

        if ($this->getMonthlyCost() >= $this->maxMonthlyCost) {
            return false;
        }

        return true;
    }

    /**
     * Check whether a per-query budget limit has been exceeded.
     *
     * @param  float  $accumulatedCost  Total cost accumulated so far in this query
     * @return bool true if within budget, false if exceeded
     */
    public function checkQueryBudget(float $accumulatedCost): bool
    {
        return $accumulatedCost < $this->maxQueryCost;
    }

    /**
     * Get the total cost for today.
     */
    public function getDailyCost(): float
    {
        return (float) AiCostLog::whereDate('created_at', Carbon::today())->sum('cost');
    }

    /**
     * Get the total cost for this month.
     */
    public function getMonthlyCost(): float
    {
        return (float) AiCostLog::whereYear('created_at', Carbon::now()->year)
            ->whereMonth('created_at', Carbon::now()->month)
            ->sum('cost');
    }

    /**
     * Check if the daily cost is approaching the limit (>= 80%).
     */
    public function isApproachingDailyLimit(): bool
    {
        return $this->getDailyCost() >= ($this->maxDailyCost * 0.80);
    }
}
