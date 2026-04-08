<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\DeviceOutage;
use App\Models\User;

class GetDeviceOutages extends AbstractAiTool
{
    public function name(): string
    {
        return 'get_device_outages';
    }

    public function description(): string
    {
        return 'Returns device outage history (times when devices went down and came back up). Useful for understanding availability and MTTR.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => [
                    'type' => 'integer',
                    'description' => 'Filter outages for a specific device ID.',
                ],
                'hostname' => [
                    'type' => 'string',
                    'description' => 'Filter outages by partial hostname match.',
                ],
                'hours' => [
                    'type' => 'integer',
                    'description' => 'Look back this many hours (default 168 = 7 days).',
                    'default' => 168,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of outage records to return (default 50, max 200).',
                    'default' => 50,
                    'maximum' => 200,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $hours = (int) ($params['hours'] ?? 168);
        $since = now()->subHours($hours)->timestamp;

        $query = DeviceOutage::query()
            ->where('going_down', '>=', $since)
            ->with('device:device_id,hostname');

        if ($user !== null) {
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (! empty($params['device_id'])) {
            $query->where('device_id', (int) $params['device_id']);
        }

        if (! empty($params['hostname'])) {
            $search = $params['hostname'];
            $query->whereHas('device', fn ($q) => $q->where('hostname', 'like', "%{$search}%"));
        }

        $limit = min((int) ($params['limit'] ?? 50), 200);
        $outages = $query->orderBy('going_down', 'desc')->limit($limit)->get();

        return [
            'count' => $outages->count(),
            'outages' => $outages->map(fn ($o) => [
                'id' => $o->id,
                'device_id' => $o->device_id,
                'hostname' => $o->device?->hostname,
                'going_down' => $o->going_down,
                'going_down_human' => $o->going_down ? date('Y-m-d H:i:s', $o->going_down) : null,
                'up_again' => $o->up_again,
                'up_again_human' => $o->up_again ? date('Y-m-d H:i:s', $o->up_again) : null,
                'duration_seconds' => ($o->up_again && $o->going_down) ? ($o->up_again - $o->going_down) : null,
            ])->values()->toArray(),
        ];
    }
}
