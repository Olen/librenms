<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Sensor;
use App\Models\User;

class GetSensors extends AbstractAiTool
{
    public function name(): string
    {
        return 'get_sensors';
    }

    public function description(): string
    {
        return 'Returns sensor readings from devices (temperature, humidity, voltage, CPU, memory, etc.). Can filter by class or only show sensors exceeding thresholds.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => [
                    'type' => 'integer',
                    'description' => 'Filter sensors for a specific device ID.',
                ],
                'class' => [
                    'type' => 'string',
                    'description' => 'Sensor class to filter by (e.g. temperature, humidity, voltage, current, fanspeed, power, load, dbm, frequency, state).',
                ],
                'alert_only' => [
                    'type' => 'boolean',
                    'description' => 'Only return sensors that are exceeding their thresholds.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of sensors to return (default 100, max 500).',
                    'default' => 100,
                    'maximum' => 500,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $query = Sensor::query()->with('device:device_id,hostname');

        if ($user !== null) {
            // @phpstan-ignore method.notFound
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (! empty($params['device_id'])) {
            $query->where('device_id', (int) $params['device_id']);
        }

        if (! empty($params['class'])) {
            $query->where('sensor_class', $params['class']);
        }

        if (! empty($params['alert_only'])) {
            $query->where(function ($q): void {
                $q->whereColumn('sensor_current', '<', 'sensor_limit_low')
                  ->orWhereColumn('sensor_current', '>', 'sensor_limit');
            });
        }

        $limit = min((int) ($params['limit'] ?? 100), 500);
        $sensors = $query->limit($limit)->get();

        return [
            'count' => $sensors->count(),
            'sensors' => $sensors->map(fn ($s) => [
                'sensor_id' => $s->sensor_id,
                'device_id' => $s->device_id,
                'hostname' => $s->device?->hostname,
                'class' => $s->sensor_class,
                'description' => $s->sensor_descr,
                'current' => $s->sensor_current,
                'limit_low' => $s->sensor_limit_low,
                'limit_low_warn' => $s->sensor_limit_low_warn,
                'limit_warn' => $s->sensor_limit_warn,
                'limit_high' => $s->sensor_limit,
            ])->values()->toArray(),
        ];
    }
}
