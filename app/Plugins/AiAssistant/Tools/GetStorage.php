<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Storage;
use App\Models\User;
use LibreNMS\Util\Number;

class GetStorage extends AbstractAiTool
{
    public function name(): string
    {
        return 'get_storage';
    }

    public function description(): string
    {
        return 'Get current disk/storage utilization for devices. Returns storage description, usage percentage, used/free/total size. Use for "which devices are running low on disk?" or "disk usage on server-1".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => ['type' => 'integer', 'description' => 'Filter to a specific device'],
                'hostname' => ['type' => 'string', 'description' => 'Filter by hostname (partial match)'],
                'min_usage' => ['type' => 'integer', 'description' => 'Only show storage with usage above this percentage'],
                'limit' => ['type' => 'integer', 'description' => 'Max results. Default: 50'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $query = Storage::query()->with('device:device_id,hostname,sysName');

        if ($user) {
            // @phpstan-ignore method.notFound
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (isset($params['device_id'])) {
            $query->where('device_id', $params['device_id']);
        }

        if (! empty($params['hostname'])) {
            $query->whereHas('device', fn ($q) => $q->where('hostname', 'like', '%' . $params['hostname'] . '%'));
        }

        if (isset($params['min_usage'])) {
            $query->where('storage_perc', '>=', $params['min_usage']);
        }

        $query->orderBy('storage_perc', 'desc');

        $limit = min($params['limit'] ?? 50, 100);
        $storages = $query->limit($limit)->get();

        return [
            'count' => $storages->count(),
            'storage' => $storages->map(fn ($s) => [
                'device' => $s->device?->hostname,
                'device_id' => $s->device_id,
                'description' => $s->storage_descr,
                'type' => $s->storage_type,
                'usage_percent' => $s->storage_perc,
                'used_bytes' => $s->storage_used,
                'free_bytes' => $s->storage_free,
                'total_bytes' => $s->storage_size,
                'used_human' => Number::formatBi($s->storage_used),
                'total_human' => Number::formatBi($s->storage_size),
                'warn_percent' => $s->storage_perc_warn,
            ])->toArray(),
        ];
    }
}
