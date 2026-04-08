<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Device;
use App\Models\User;

class GetDeviceDetail extends AbstractAiTool
{
    public function name(): string
    {
        return 'get_device_detail';
    }

    public function description(): string
    {
        return 'Returns detailed information about a specific device including port summary, sensor list, and active alerts. Provide either hostname (partial match) or device_id.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'hostname' => [
                    'type' => 'string',
                    'description' => 'Partial hostname to search for (case-insensitive).',
                ],
                'device_id' => [
                    'type' => 'integer',
                    'description' => 'Exact device ID.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $query = Device::query()->with(['sensors', 'alerts' => fn ($q) => $q->where('state', '>=', 1)->with('rule'), 'location']);

        if ($user !== null) {
            $query->hasAccess($user);
        }

        if (! empty($params['device_id'])) {
            $query->where('device_id', (int) $params['device_id']);
        } elseif (! empty($params['hostname'])) {
            $query->where('hostname', 'like', '%' . $params['hostname'] . '%');
        } else {
            return ['error' => 'Either hostname or device_id is required.'];
        }

        $device = $query->first();

        if (! $device) {
            return ['error' => 'Device not found.'];
        }

        $portQuery = $device->ports()->where('deleted', 0);
        $totalPorts = (clone $portQuery)->count();
        $portsUp = (clone $portQuery)->where('ifOperStatus', 'up')->count();
        $portsDown = (clone $portQuery)->where('ifOperStatus', '!=', 'up')->count();

        return [
            'device_id' => $device->device_id,
            'hostname' => $device->hostname,
            'sysName' => $device->sysName,
            'status' => $device->status ? 'up' : 'down',
            'os' => $device->os,
            'uptime' => $device->uptime,
            'hardware' => $device->hardware,
            'version' => $device->version,
            'serial' => $device->serial,
            'location' => $device->location?->location,
            'ip' => $device->ip,
            'last_polled' => $device->last_polled?->toIso8601String(),
            'port_summary' => [
                'total' => $totalPorts,
                'up' => $portsUp,
                'down' => $portsDown,
            ],
            'sensors' => $device->sensors->map(fn ($s) => [
                'sensor_id' => $s->sensor_id,
                'class' => $s->sensor_class,
                'description' => $s->sensor_descr,
                'current' => $s->sensor_current,
                'limit_low' => $s->sensor_limit_low,
                'limit_high' => $s->sensor_limit,
            ])->values()->toArray(),
            'active_alerts' => $device->alerts->map(fn ($a) => [
                'id' => $a->id,
                'rule' => $a->rule?->name,
                'state' => $a->state >= 2 ? 'acknowledged' : 'active',
                'timestamp' => $a->timestamp,
            ])->values()->toArray(),
        ];
    }
}
