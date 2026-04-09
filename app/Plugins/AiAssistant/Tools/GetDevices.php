<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Device;
use App\Models\User;

class GetDevices extends AbstractAiTool
{
    protected ?string $authorizedModel = Device::class;

    public function name(): string
    {
        return 'get_devices';
    }

    public function description(): string
    {
        return 'Returns a list of devices with optional filtering by status, OS, location, or group. Useful for finding specific devices or getting an overview of device inventory.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['up', 'down', 'all'],
                    'description' => 'Filter by device status. Defaults to all.',
                ],
                'os' => [
                    'type' => 'string',
                    'description' => 'Filter by operating system (exact match, e.g. ios, junos, linux).',
                ],
                'location' => [
                    'type' => 'string',
                    'description' => 'Filter by location name (substring match).',
                ],
                'group' => [
                    'type' => 'string',
                    'description' => 'Filter by device group name (substring match).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of devices to return (default 50, max 100).',
                    'default' => 50,
                    'maximum' => 100,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $query = Device::query()->with('location:id,location');

        if ($user !== null) {
            $query->hasAccess($user);
        }

        $status = $params['status'] ?? 'all';
        if ($status === 'up') {
            $query->where('status', 1);
        } elseif ($status === 'down') {
            $query->where('status', 0);
        }

        if (! empty($params['os'])) {
            $query->where('os', $params['os']);
        }

        if (! empty($params['location'])) {
            $search = $params['location'];
            $query->whereHas('location', fn ($q) => $q->where('location', 'like', "%{$search}%"));
        }

        if (! empty($params['group'])) {
            $search = $params['group'];
            $query->whereHas('groups', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        }

        $limit = min((int) ($params['limit'] ?? 50), 100);
        $devices = $query->limit($limit)->get();

        return [
            'count' => $devices->count(),
            'devices' => $devices->map(fn ($d) => [
                'device_id' => $d->device_id,
                'hostname' => $d->hostname,
                'sysName' => $d->sysName,
                'status' => $d->status ? 'up' : 'down',
                'os' => $d->os,
                'uptime' => $d->uptime,
                'hardware' => $d->hardware,
                'version' => $d->version,
                'location' => $d->location?->location,
            ])->values()->toArray(),
        ];
    }
}
