<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Mempool;
use App\Models\User;
use LibreNMS\Util\Number;

class GetMempools extends AbstractAiTool
{
    public function name(): string
    {
        return 'get_mempools';
    }

    public function description(): string
    {
        return 'Get current memory utilization for devices. Returns memory pool description, usage percentage, used/free/total bytes. Use for "which devices are low on memory?" or "memory usage on switch-1".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => ['type' => 'integer', 'description' => 'Filter to a specific device'],
                'hostname' => ['type' => 'string', 'description' => 'Filter by hostname (partial match)'],
                'min_usage' => ['type' => 'integer', 'description' => 'Only show mempools with usage above this percentage'],
                'limit' => ['type' => 'integer', 'description' => 'Max results. Default: 50'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $query = Mempool::query()->with('device:device_id,hostname,sysName');

        if ($user) {
            // @phpstan-ignore-next-line
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (isset($params['device_id'])) {
            $query->where('device_id', $params['device_id']);
        }

        if (! empty($params['hostname'])) {
            $query->whereHas('device', fn ($q) => $q->where('hostname', 'like', '%' . $params['hostname'] . '%'));
        }

        if (isset($params['min_usage'])) {
            $query->where('mempool_perc', '>=', $params['min_usage']);
        }

        $query->orderBy('mempool_perc', 'desc');

        $limit = min($params['limit'] ?? 50, 100);
        $mempools = $query->limit($limit)->get();

        return [
            'count' => $mempools->count(),
            'mempools' => $mempools->map(fn ($m) => [
                'device' => $m->device?->hostname,
                'device_id' => $m->device_id,
                'description' => $m->mempool_descr,
                'usage_percent' => $m->mempool_perc,
                'used_bytes' => $m->mempool_used,
                'free_bytes' => $m->mempool_free,
                'total_bytes' => $m->mempool_total,
                'used_human' => Number::formatBi($m->mempool_used),
                'total_human' => Number::formatBi($m->mempool_total),
                'warn_percent' => $m->mempool_perc_warn,
            ])->toArray(),
        ];
    }
}
