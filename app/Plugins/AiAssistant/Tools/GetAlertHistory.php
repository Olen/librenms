<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\AlertLog;
use App\Models\User;

class GetAlertHistory extends AbstractAiTool
{
    public function name(): string
    {
        return 'get_alert_history';
    }

    public function description(): string
    {
        return 'Returns historical alert events (fired, recovered, acknowledged) over a time window. Useful for understanding recent alert activity.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'hours' => [
                    'type' => 'integer',
                    'description' => 'Look back this many hours (default 24).',
                    'default' => 24,
                ],
                'device_id' => [
                    'type' => 'integer',
                    'description' => 'Filter history for a specific device ID.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of records to return (default 100, max 500).',
                    'default' => 100,
                    'maximum' => 500,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $hours = (int) ($params['hours'] ?? 24);
        $since = now()->subHours($hours);

        $query = AlertLog::query()
            ->where('time_logged', '>=', $since)
            ->with(['device:device_id,hostname', 'rule:id,name']);

        if ($user !== null) {
            // @phpstan-ignore-next-line
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (! empty($params['device_id'])) {
            $query->where('device_id', (int) $params['device_id']);
        }

        $limit = min((int) ($params['limit'] ?? 100), 500);
        $logs = $query->orderBy('time_logged', 'desc')->limit($limit)->get();

        $stateMap = [
            0 => 'recovered',
            1 => 'fired',
            2 => 'acknowledged',
        ];

        return [
            'count' => $logs->count(),
            'history' => $logs->map(fn ($log) => [
                'id' => $log->id,
                'device_id' => $log->device_id,
                'hostname' => $log->device?->hostname,
                'rule' => $log->rule?->name,
                'state' => $stateMap[$log->getRawOriginal('state')] ?? 'unknown',
                'time_logged' => $log->time_logged->toIso8601String(),
            ])->values()->toArray(),
        ];
    }
}
