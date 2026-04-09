<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Facades\LibrenmsConfig;
use App\Models\Alert;
use App\Models\Device;
use App\Models\Port;
use App\Models\Service;
use App\Models\User;

class GetNetworkSummary extends AbstractAiTool
{
    // Aggregates across devices, alerts, ports and services. Without any
    // device visibility the summary is meaningless, so authorize against
    // Device and inherit the AbstractAiTool fallback. Per-entity scoping
    // is still enforced inside execute() via hasAccess() on each query.
    protected ?string $authorizedModel = Device::class;

    public function name(): string
    {
        return 'get_network_summary';
    }

    public function description(): string
    {
        return 'Returns a high-level summary of the network: total devices, up/down counts, active alerts, port statistics, and optionally service status.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $deviceQuery = Device::query();
        if ($user !== null) {
            $deviceQuery->hasAccess($user);
        }

        $totalDevices = (clone $deviceQuery)->count();
        $devicesUp = (clone $deviceQuery)->where('status', 1)->count();
        $devicesDown = (clone $deviceQuery)->where('status', 0)->count();
        $downDeviceNames = (clone $deviceQuery)
            ->where('status', 0)
            ->pluck('hostname')
            ->toArray();

        $alertQuery = Alert::query()->where('state', '>=', 1);
        if ($user !== null) {
            // @phpstan-ignore-next-line
            $alertQuery->whereHas('device', fn ($q) => $q->hasAccess($user));
        }
        $activeAlerts = $alertQuery->count();

        $portQuery = Port::query()->where('deleted', 0);
        if ($user !== null) {
            $portQuery->hasAccess($user);
        }
        $totalPorts = (clone $portQuery)->count();
        $portsUp = (clone $portQuery)->where('ifOperStatus', 'up')->count();
        $portsDown = (clone $portQuery)->where('ifOperStatus', '!=', 'up')->count();

        $result = [
            'total_devices' => $totalDevices,
            'devices_up' => $devicesUp,
            'devices_down' => $devicesDown,
            'down_device_names' => $downDeviceNames,
            'active_alerts' => $activeAlerts,
            'total_ports' => $totalPorts,
            'ports_up' => $portsUp,
            'ports_down' => $portsDown,
        ];

        if (LibrenmsConfig::get('show_services')) {
            $serviceQuery = Service::query();
            if ($user !== null) {
                // @phpstan-ignore-next-line
                $serviceQuery->whereHas('device', fn ($q) => $q->hasAccess($user));
            }
            $result['services_ok'] = (clone $serviceQuery)->where('service_status', 0)->count();
            $result['services_warning'] = (clone $serviceQuery)->where('service_status', 1)->count();
            $result['services_critical'] = (clone $serviceQuery)->where('service_status', 2)->count();
        }

        return $result;
    }
}
