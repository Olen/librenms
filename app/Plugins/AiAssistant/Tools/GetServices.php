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
        return 'Returns service monitoring check results. Services are monitoring checks attached to devices — they can be any type including custom scripts. Call without filters first to see what service types exist, then narrow down. Supports filtering by hostname, service name/type, and text search.';
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
                'hostname' => [
                    'type' => 'string',
                    'description' => 'Filter by device hostname (partial match).',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['ok', 'warning', 'critical'],
                    'description' => 'Filter by service check status.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Search in service type, name, and description (substring match). Use this to find services like "restic", "imap", "dns", etc.',
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
            // @phpstan-ignore method.notFound
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (! empty($params['device_id'])) {
            $query->where('device_id', (int) $params['device_id']);
        }

        if (! empty($params['hostname'])) {
            $query->whereHas('device', fn ($q) => $q->where('hostname', 'like', '%' . $params['hostname'] . '%'));
        }

        if (! empty($params['status']) && isset($statusMap[$params['status']])) {
            $query->where('service_status', $statusMap[$params['status']]);
        }

        if (! empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('service_type', 'like', '%' . $search . '%')
                  ->orWhere('service_name', 'like', '%' . $search . '%')
                  ->orWhere('service_desc', 'like', '%' . $search . '%');
            });
        }

        // Include summary of available service types
        $typesQuery = Service::query();
        if ($user !== null) {
            // @phpstan-ignore method.notFound
            $typesQuery->whereHas('device', fn ($q) => $q->hasAccess($user));
        }
        $availableTypes = $typesQuery->selectRaw('service_type, count(*) as cnt')
            ->groupBy('service_type')
            ->pluck('cnt', 'service_type')
            ->toArray();

        $limit = min((int) ($params['limit'] ?? 50), 200);
        $services = $query->limit($limit)->get();

        $statusLabels = [0 => 'ok', 1 => 'warning', 2 => 'critical'];

        return [
            'available_types' => $availableTypes,
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
