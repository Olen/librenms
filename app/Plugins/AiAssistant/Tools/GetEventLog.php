<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Eventlog;
use App\Models\User;

class GetEventLog extends AbstractAiTool
{
    public function name(): string
    {
        return 'get_event_log';
    }

    public function description(): string
    {
        return 'Returns LibreNMS event log entries. IMPORTANT: For external messages (type "external") like backup status, fail2ban, or other script-generated events, ALWAYS use the "search" parameter to find them by keywords in the message text (e.g. search="restic" or search="lupus"). These events have NO device_id, so filtering by device_id will NOT find them. The search parameter does substring matching on the message field. Event types include: sensor, interface, state, system, external.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => [
                    'type' => 'integer',
                    'description' => 'Filter events for a specific device ID.',
                ],
                'hours' => [
                    'type' => 'integer',
                    'description' => 'Look back this many hours (default 24).',
                    'default' => 24,
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'Filter by event type (e.g. sensor, interface, state, system, external).',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Search for text in the event message (substring match, case-insensitive).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of events to return (default 100, max 500).',
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

        $query = Eventlog::query()
            ->where('datetime', '>=', $since)
            ->with('device:device_id,hostname');

        if ($user !== null) {
            // Include events the user can access via device permissions,
            // AND events not associated with any device (e.g. external/system events)
            $query->where(function ($q) use ($user) {
                $q->whereNull('device_id')
                  ->orWhere('device_id', 0)
                  ->orWhereHas('device', fn ($dq) => $dq->hasAccess($user));
            });
        }

        if (! empty($params['device_id'])) {
            $query->where('device_id', (int) $params['device_id']);
        }

        if (! empty($params['type'])) {
            $query->where('type', $params['type']);
        }

        if (! empty($params['search'])) {
            $query->where('message', 'like', '%' . $params['search'] . '%');
        }

        $limit = min((int) ($params['limit'] ?? 100), 500);
        $events = $query->orderBy('datetime', 'desc')->limit($limit)->get();

        return [
            'count' => $events->count(),
            'events' => $events->map(fn ($e) => [
                'event_id' => $e->event_id,
                'device_id' => $e->device_id,
                'hostname' => $e->device?->hostname,
                'datetime' => $e->datetime,
                'message' => $e->message,
                'type' => $e->type,
                'severity' => $e->severity instanceof \BackedEnum ? $e->severity->value : $e->severity,
                'username' => $e->username,
            ])->values()->toArray(),
        ];
    }
}
