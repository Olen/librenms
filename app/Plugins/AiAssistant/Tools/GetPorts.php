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
        return 'Returns network port/interface information. Filter by device, operational status, or ports with errors. Includes current traffic rates (bps), utilization percentage relative to port speed, and error counters. Use for "which ports are most utilized?" or "show ports with high traffic on switch-1".';
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
                'ifSpeed_human' => $p->ifSpeed ? \LibreNMS\Util\Number::formatSi($p->ifSpeed, 2, 0, 'bps') : null,
                'in_rate_bps' => $p->ifInOctets_rate ? $p->ifInOctets_rate * 8 : 0,
                'out_rate_bps' => $p->ifOutOctets_rate ? $p->ifOutOctets_rate * 8 : 0,
                'in_rate_human' => $p->ifInOctets_rate ? \LibreNMS\Util\Number::formatSi($p->ifInOctets_rate * 8, 2, 0, 'bps') : '0 bps',
                'out_rate_human' => $p->ifOutOctets_rate ? \LibreNMS\Util\Number::formatSi($p->ifOutOctets_rate * 8, 2, 0, 'bps') : '0 bps',
                'in_utilization_pct' => ($p->ifSpeed > 0 && $p->ifInOctets_rate) ? round(($p->ifInOctets_rate * 8 / $p->ifSpeed) * 100, 1) : null,
                'out_utilization_pct' => ($p->ifSpeed > 0 && $p->ifOutOctets_rate) ? round(($p->ifOutOctets_rate * 8 / $p->ifSpeed) * 100, 1) : null,
                'in_errors_delta' => $p->ifInErrors_delta,
                'out_errors_delta' => $p->ifOutErrors_delta,
            ])->values()->toArray(),
        ];
    }
}
