<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Service;
use App\Models\User;

class GetServices extends AbstractAiTool
{
    public function name(): string
    {
        return 'get_services';
    }

    public function description(): string
    {
        return 'Returns Nagios-style service check results (HTTP, HTTPS, DNS, SMTP, etc.) monitored by LibreNMS.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => [
                    'type' => 'integer',
                    'description' => 'Filter services for a specific device ID.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['ok', 'warning', 'critical'],
                    'description' => 'Filter by service check status.',
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'Filter by service type/check (e.g. check_http, check_dns).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of services to return (default 50, max 200).',
                    'default' => 50,
                    'maximum' => 200,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $statusMap = [
            'ok' => 0,
            'warning' => 1,
            'critical' => 2,
        ];

        $query = Service::query()->with('device:device_id,hostname');

        if ($user !== null) {
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (! empty($params['device_id'])) {
            $query->where('device_id', (int) $params['device_id']);
        }

        if (! empty($params['status']) && isset($statusMap[$params['status']])) {
            $query->where('service_status', $statusMap[$params['status']]);
        }

        if (! empty($params['type'])) {
            $query->where('service_type', $params['type']);
        }

        $limit = min((int) ($params['limit'] ?? 50), 200);
        $services = $query->limit($limit)->get();

        $statusLabels = [0 => 'ok', 1 => 'warning', 2 => 'critical'];

        return [
            'count' => $services->count(),
            'services' => $services->map(fn ($s) => [
                'service_id' => $s->service_id,
                'device_id' => $s->device_id,
                'hostname' => $s->device?->hostname,
                'name' => $s->service_name,
                'type' => $s->service_type,
                'desc' => $s->service_desc,
                'ip' => $s->service_ip,
                'status' => $statusLabels[$s->service_status] ?? 'unknown',
                'message' => $s->service_message,
                'ignored' => (bool) $s->service_ignore,
                'disabled' => (bool) $s->service_disabled,
            ])->values()->toArray(),
        ];
    }
}
