<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Processor;
use App\Models\User;

class GetProcessors extends AbstractAiTool
{
    public function name(): string
    {
        return 'get_processors';
    }

    public function description(): string
    {
        return 'Get current CPU/processor utilization for devices. Returns processor description, current usage percentage, and warning threshold. Use for "which devices have high CPU?" or "what is the CPU usage on router-1?"';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => ['type' => 'integer', 'description' => 'Filter to a specific device'],
                'hostname' => ['type' => 'string', 'description' => 'Filter by hostname (partial match)'],
                'min_usage' => ['type' => 'integer', 'description' => 'Only show processors with usage above this percentage'],
                'limit' => ['type' => 'integer', 'description' => 'Max results. Default: 50'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $query = Processor::query()->with('device:device_id,hostname,sysName');

        if ($user) {
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (isset($params['device_id'])) {
            $query->where('device_id', $params['device_id']);
        }

        if (! empty($params['hostname'])) {
            $query->whereHas('device', fn ($q) => $q->where('hostname', 'like', '%' . $params['hostname'] . '%'));
        }

        if (isset($params['min_usage'])) {
            $query->where('processor_usage', '>=', $params['min_usage']);
        }

        $query->orderBy('processor_usage', 'desc');

        $limit = min($params['limit'] ?? 50, 100);
        $processors = $query->limit($limit)->get();

        return [
            'count' => $processors->count(),
            'processors' => $processors->map(fn ($p) => [
                'device' => $p->device?->hostname,
                'device_id' => $p->device_id,
                'description' => $p->processor_descr,
                'usage_percent' => $p->processor_usage,
                'warn_percent' => $p->processor_perc_warn,
            ])->toArray(),
        ];
    }
}
