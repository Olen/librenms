<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Port;
use App\Models\User;

class GetPorts extends AbstractAiTool
{
    public function name(): string
    {
        return 'get_ports';
    }

    public function description(): string
    {
        return 'Returns network port/interface information. Filter by device, operational status, or ports with errors. Includes traffic rates and error counters.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => [
                    'type' => 'integer',
                    'description' => 'Filter ports for a specific device ID.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['up', 'down', 'admin_down'],
                    'description' => 'Filter by port operational status.',
                ],
                'has_errors' => [
                    'type' => 'boolean',
                    'description' => 'Only return ports with input or output errors.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of ports to return (default 50, max 200).',
                    'default' => 50,
                    'maximum' => 200,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $query = Port::query()->where('deleted', 0)->with('device:device_id,hostname');

        if ($user !== null) {
            $query->hasAccess($user);
        }

        if (! empty($params['device_id'])) {
            $query->where('device_id', (int) $params['device_id']);
        }

        if (! empty($params['status'])) {
            switch ($params['status']) {
                case 'up':
                    $query->where('ifOperStatus', 'up');
                    break;
                case 'down':
                    $query->where('ifOperStatus', '!=', 'up')->where('ifAdminStatus', 'up');
                    break;
                case 'admin_down':
                    $query->where('ifAdminStatus', 'down');
                    break;
            }
        }

        if (! empty($params['has_errors'])) {
            $query->hasErrors();
        }

        $limit = min((int) ($params['limit'] ?? 50), 200);
        $ports = $query->limit($limit)->get();

        return [
            'count' => $ports->count(),
            'ports' => $ports->map(fn ($p) => [
                'port_id' => $p->port_id,
                'device_id' => $p->device_id,
                'hostname' => $p->device?->hostname,
                'ifName' => $p->ifName,
                'ifAlias' => $p->ifAlias,
                'ifDescr' => $p->ifDescr,
                'ifOperStatus' => $p->ifOperStatus,
                'ifAdminStatus' => $p->ifAdminStatus,
                'ifSpeed' => $p->ifSpeed,
                'in_rate' => $p->ifInOctets_rate,
                'out_rate' => $p->ifOutOctets_rate,
                'in_errors_delta' => $p->ifInErrors_delta,
                'out_errors_delta' => $p->ifOutErrors_delta,
            ])->values()->toArray(),
        ];
    }
}
