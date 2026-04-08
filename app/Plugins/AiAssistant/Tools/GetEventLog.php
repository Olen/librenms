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
                    'description' => 'Filter events for a specific device ID. NOTE: ignored when type="external" because external events have no device. Use "search" instead to find external events by keyword.',
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
        \Log::info('GetEventLog called with params: ' . json_encode($params));
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
            $deviceId = (int) $params['device_id'];
            // Match events for this device OR device-less events (type=external)
            // that mention the device hostname in the message text.
            $hostname = \App\Models\Device::where('device_id', $deviceId)->value('hostname');
            $shortName = $hostname ? explode('.', $hostname)[0] : null;
            $query->where(function ($q) use ($deviceId, $shortName) {
                $q->where('device_id', $deviceId);
                if ($shortName) {
                    $q->orWhere(function ($sub) use ($shortName) {
                        $sub->where(function ($inner) {
                            $inner->whereNull('device_id')->orWhere('device_id', 0);
                        })->where('message', 'like', '%' . $shortName . '%');
                    });
                }
            });
        }

        if (! empty($params['type'])) {
            $query->where('type', $params['type']);
        }

        if (! empty($params['search'])) {
            // Split search into words and match each independently
            // so "restic lupus" matches "Restic Backup 61lupus"
            $words = preg_split('/\s+/', trim($params['search']));
            foreach ($words as $word) {
                if ($word !== '') {
                    $query->where('message', 'like', '%' . $word . '%');
                }
            }
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
