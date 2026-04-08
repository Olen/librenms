<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Alert;
use App\Models\User;

class GetActiveAlerts extends AbstractAiTool
{
    public function name(): string
    {
        return 'get_active_alerts';
    }

    public function description(): string
    {
        return 'Returns currently active (firing or acknowledged) alerts. Filter by severity, device, or limit the count.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'severity' => [
                    'type' => 'string',
                    'enum' => ['ok', 'warning', 'critical'],
                    'description' => 'Filter alerts by severity level.',
                ],
                'device_id' => [
                    'type' => 'integer',
                    'description' => 'Filter alerts for a specific device ID.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of alerts to return (default 50, max 200).',
                    'default' => 50,
                    'maximum' => 200,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $query = Alert::query()
            ->where('state', '>=', 1)
            ->with(['device:device_id,hostname', 'rule:id,name,severity']);

        if ($user !== null) {
            // @phpstan-ignore-next-line
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (! empty($params['severity'])) {
            $query->whereHas('rule', fn ($q) => $q->where('severity', $params['severity']));
        }

        if (! empty($params['device_id'])) {
            $query->where('device_id', (int) $params['device_id']);
        }

        $limit = min((int) ($params['limit'] ?? 50), 200);
        $alerts = $query->orderBy('timestamp', 'desc')->limit($limit)->get();

        return [
            'count' => $alerts->count(),
            'alerts' => $alerts->map(fn ($a) => [
                'id' => $a->id,
                'device_id' => $a->device_id,
                'hostname' => $a->device?->hostname,
                'rule' => $a->rule?->name,
                'severity' => $a->rule?->severity,
                'state' => $a->state >= 2 ? 'acknowledged' : 'active',
                'timestamp' => $a->timestamp,
                'note' => $a->note,
            ])->values()->toArray(),
        ];
    }
}
