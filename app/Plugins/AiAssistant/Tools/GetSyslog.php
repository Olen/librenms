<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Syslog;
use App\Models\User;

class GetSyslog extends AbstractAiTool
{
    public function name(): string
    {
        return 'get_syslog';
    }

    public function description(): string
    {
        return 'Returns syslog messages received from devices. Filter by device, priority, program, or search text.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => [
                    'type' => 'integer',
                    'description' => 'Filter syslog for a specific device ID.',
                ],
                'hours' => [
                    'type' => 'integer',
                    'description' => 'Look back this many hours (default 24).',
                    'default' => 24,
                ],
                'priority' => [
                    'type' => 'string',
                    'description' => 'Filter by syslog priority (e.g. emerg, alert, crit, err, warning, notice, info, debug).',
                ],
                'program' => [
                    'type' => 'string',
                    'description' => 'Filter by program/process name.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Search for a substring in the syslog message.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of messages to return (default 100, max 500).',
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

        $query = Syslog::query()
            ->where('timestamp', '>=', $since)
            ->with('device:device_id,hostname');

        if ($user !== null) {
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (! empty($params['device_id'])) {
            $query->where('device_id', (int) $params['device_id']);
        }

        if (! empty($params['priority'])) {
            $query->where('priority', $params['priority']);
        }

        if (! empty($params['program'])) {
            $query->where('program', $params['program']);
        }

        if (! empty($params['search'])) {
            $search = $params['search'];
            $query->where('msg', 'like', "%{$search}%");
        }

        $limit = min((int) ($params['limit'] ?? 100), 500);
        $logs = $query->orderBy('timestamp', 'desc')->limit($limit)->get();

        return [
            'count' => $logs->count(),
            'messages' => $logs->map(fn ($l) => [
                'seq' => $l->seq,
                'device_id' => $l->device_id,
                'hostname' => $l->device?->hostname,
                'timestamp' => $l->timestamp,
                'priority' => $l->priority,
                'level' => $l->level,
                'facility' => $l->facility,
                'program' => $l->program,
                'msg' => $l->msg,
            ])->values()->toArray(),
        ];
    }
}
