<?php

/*
 * ContextBuilder.php
 *
 * Builds a compact network status snapshot for the AI assistant system prompt.
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

use App\Models\Alert;
use App\Models\Device;
use App\Models\Eventlog;
use App\Models\Port;
use App\Models\User;
use Carbon\Carbon;
use LibreNMS\Enum\AlertState;

class ContextBuilder
{
    /**
     * Build a compact network status snapshot string for the LLM system prompt.
     */
    public function buildContextSnapshot(?User $user = null): string
    {
        $lines = [];

        // Device counts
        $deviceQuery = Device::query();
        if ($user) {
            $deviceQuery->hasAccess($user);
        }
        $totalDevices = (clone $deviceQuery)->count();
        $upDevices = (clone $deviceQuery)->where('status', 1)->where('disabled', 0)->where('ignore', 0)->count();
        $downDevices = (clone $deviceQuery)->isDown()->count();

        $downDeviceNames = (clone $deviceQuery)->isDown()
            ->limit(10)
            ->pluck('hostname')
            ->toArray();

        $deviceLine = "Network Status: {$totalDevices} devices ({$upDevices} up, {$downDevices} down";
        if (! empty($downDeviceNames)) {
            $deviceLine .= ': ' . implode(', ', $downDeviceNames);
        }
        $deviceLine .= ')';
        $lines[] = $deviceLine;

        // Active alerts
        $alertQuery = Alert::query()->where('state', AlertState::ACTIVE);
        if ($user) {
            $alertQuery->whereHas('device', function ($q) use ($user) {
                $q->hasAccess($user);
            });
        }
        $activeAlerts = $alertQuery->count();
        $lines[] = "Active Alerts: {$activeAlerts}";

        // Ports down (admin up but oper down)
        $portQuery = Port::query()->isDown();
        if ($user) {
            $portQuery->hasAccess($user);
        }
        $portsDown = $portQuery->count();
        $lines[] = "Ports: {$portsDown} down (admin up but oper down)";

        // Recent events (last hour)
        $eventQuery = Eventlog::query()->where('datetime', '>=', Carbon::now()->subHour());
        if ($user) {
            $eventQuery->whereHas('device', function ($q) use ($user) {
                $q->hasAccess($user);
            });
        }
        $recentEvents = $eventQuery->count();
        $lines[] = "Last hour: {$recentEvents} events";

        // Current time
        $now = Carbon::now();
        $lines[] = "Current time: {$now->format('Y-m-d H:i:s')} ({$now->format('l')})";

        return implode("\n", $lines);
    }
}
