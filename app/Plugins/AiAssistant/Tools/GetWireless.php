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
        return 'Get wireless/WiFi sensor data. Call with no sensor_class filter first to see which sensor classes are available in the network, then drill down. Common classes: clients (connected users), power (signal strength in dBm), utilization (channel usage %), frequency (channel frequency), rssi, snr, quality, noise-floor, rate, ap-count. Not all classes may exist in every network.';
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
            // @phpstan-ignore method.notFound
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

        // Always include a summary of available classes so the LLM knows what's in the network
        $classQuery = WirelessSensor::query();
        if ($user) {
            // @phpstan-ignore method.notFound
            $classQuery->whereHas('device', fn ($q) => $q->hasAccess($user));
        }
        $availableClasses = $classQuery->selectRaw('sensor_class, count(*) as cnt')
            ->groupBy('sensor_class')
            ->pluck('cnt', 'sensor_class')
            ->toArray();

        $limit = min($params['limit'] ?? 50, 100);
        $sensors = $query->limit($limit)->get();

        return [
            'available_classes' => $availableClasses,
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
