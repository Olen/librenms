<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\User;
use App\Models\WirelessSensor;

class GetWireless extends AbstractAiTool
{
    public function name(): string
    {
        return 'get_wireless';
    }

    public function description(): string
    {
        return 'Get wireless/WiFi sensor data including client counts, signal quality, RSSI, SNR, channel utilization, AP counts, data rates, noise floor, and more. Use for "how many WiFi clients are connected?" or "what is the signal quality on the APs?" or "which access points have poor signal?"';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => ['type' => 'integer', 'description' => 'Filter to a specific device'],
                'hostname' => ['type' => 'string', 'description' => 'Filter by hostname (partial match)'],
                'sensor_class' => [
                    'type' => 'string',
                    'description' => 'Filter by sensor type. Common values: clients, quality, rssi, snr, utilization, rate, ap-count, power, noise-floor, frequency, channel, capacity, ccq, errors',
                ],
                'limit' => ['type' => 'integer', 'description' => 'Max results. Default: 50'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $query = WirelessSensor::query()->with('device:device_id,hostname,sysName');

        if ($user) {
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (isset($params['device_id'])) {
            $query->where('device_id', $params['device_id']);
        }

        if (! empty($params['hostname'])) {
            $query->whereHas('device', fn ($q) => $q->where('hostname', 'like', '%' . $params['hostname'] . '%'));
        }

        if (! empty($params['sensor_class'])) {
            $query->where('sensor_class', $params['sensor_class']);
        }

        $limit = min($params['limit'] ?? 50, 100);
        $sensors = $query->limit($limit)->get();

        return [
            'count' => $sensors->count(),
            'wireless_sensors' => $sensors->map(fn ($s) => [
                'device' => $s->device?->hostname,
                'device_id' => $s->device_id,
                'class' => $s->sensor_class->value,
                'class_description' => $s->classDescr(),
                'description' => $s->sensor_descr,
                'current_value' => $s->sensor_current,
                'formatted_value' => $s->formatValue(),
                'unit' => $s->unit(),
                'limit_high' => $s->sensor_limit,
                'limit_low' => $s->sensor_limit_low,
                'limit_warn' => $s->sensor_limit_warn,
            ])->toArray(),
        ];
    }
}
