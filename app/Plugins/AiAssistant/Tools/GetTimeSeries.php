<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Device;
use App\Models\Port;
use App\Models\Storage;
use App\Models\User;
use Symfony\Component\Process\Process;

class GetTimeSeries extends AbstractAiTool
{
    // Time-series data is always resolved through a device, so authorize
    // against Device. The AbstractAiTool fallback lets users with explicit
    // device assignments through; per-device access is still enforced
    // inside execute() via the hasAccess() scope on the device lookup.
    protected ?string $authorizedModel = Device::class;

    public function name(): string
    {
        return 'get_time_series';
    }

    public function description(): string
    {
        return 'Fetch historical time-series data from RRD files. Use this for trend analysis, capacity planning, and anomaly detection. '
            . 'First call with action="list" to see available metrics for a device, then call with action="fetch" to get data points. '
            . 'For storage/disk trends, use metric="storage" with the storage description. '
            . 'For port/traffic trends, use metric="port" with the port ifName. '
            . 'Returns sampled data points (not every poll cycle) suitable for trend analysis.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list', 'fetch'],
                    'description' => 'Action: "list" to see available metrics for a device, "fetch" to get data points.',
                ],
                'hostname' => [
                    'type' => 'string',
                    'description' => 'Device hostname (partial match for list, exact for fetch).',
                ],
                'metric' => [
                    'type' => 'string',
                    'enum' => ['storage', 'port', 'processor', 'mempool', 'custom'],
                    'description' => 'Type of metric to fetch. Use "storage" for disk, "port" for traffic, etc.',
                ],
                'metric_name' => [
                    'type' => 'string',
                    'description' => 'Specific metric identifier. For storage: the storage description (e.g. "/", "/home"). For port: the ifName (e.g. "eth0", "ge-0/0/0"). For custom: the RRD filename without .rrd extension.',
                ],
                'hours' => [
                    'type' => 'integer',
                    'description' => 'How many hours of data to fetch. Default: 168 (7 days). Max: 8760 (1 year).',
                ],
                'data_points' => [
                    'type' => 'integer',
                    'description' => 'Target number of data points to return (data is sampled). Default: 24. Max: 48. Keep low to avoid token limits.',
                ],
            ],
            'required' => ['action', 'hostname'],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $action = $params['action'] ?? 'list';
        $hostname = $params['hostname'] ?? '';

        // Resolve hostname
        $deviceQuery = Device::query()->where('hostname', 'like', '%' . $hostname . '%');
        if ($user) {
            $deviceQuery->hasAccess($user);
        }
        $device = $deviceQuery->first();

        if (! $device) {
            return ['error' => "Device not found: {$hostname}"];
        }

        $rrdDir = '/data/rrd/' . $device->hostname;
        if (! is_dir($rrdDir)) {
            // Try alternate path
            $rrdDir = base_path('rrd/' . $device->hostname);
        }
        if (! is_dir($rrdDir)) {
            return ['error' => "No RRD data directory found for {$device->hostname}"];
        }

        if ($action === 'list') {
            return $this->listMetrics($device, $rrdDir, $user);
        }

        return $this->fetchData($device, $rrdDir, $params);
    }

    private function listMetrics(Device $device, string $rrdDir, ?User $user): array
    {
        $metrics = [];

        // Storage
        $storages = Storage::where('device_id', $device->device_id)->get();
        foreach ($storages as $s) {
            $metrics[] = [
                'type' => 'storage',
                'name' => $s->storage_descr,
                'current_percent' => $s->storage_perc,
                'current_used_human' => \LibreNMS\Util\Number::formatBi($s->storage_used),
                'total_human' => \LibreNMS\Util\Number::formatBi($s->storage_size),
            ];
        }

        // Ports (only up or with traffic)
        $ports = Port::where('device_id', $device->device_id)
            ->where('deleted', 0)
            ->where('disabled', 0)
            ->get();
        foreach ($ports as $p) {
            $metrics[] = [
                'type' => 'port',
                'name' => $p->ifName,
                'description' => $p->ifAlias ?: $p->ifDescr,
                'speed' => $p->ifSpeed,
                'status' => $p->ifOperStatus,
            ];
        }

        // List other RRD files by category
        $rrdFiles = glob($rrdDir . '/*.rrd');
        $categories = [];
        foreach ($rrdFiles as $f) {
            $basename = basename($f, '.rrd');
            if (preg_match('/^(processor|mempool|sensor|app|bgp|availability|icmp)/', $basename, $m)) {
                $cat = $m[1];
                if (! isset($categories[$cat])) {
                    $categories[$cat] = 0;
                }
                $categories[$cat]++;
            }
        }

        return [
            'device' => $device->hostname,
            'storage' => array_filter($metrics, fn ($m) => $m['type'] === 'storage'),
            'ports' => array_values(array_filter($metrics, fn ($m) => $m['type'] === 'port')),
            'other_metrics' => $categories,
        ];
    }

    private function fetchData(Device $device, string $rrdDir, array $params): array
    {
        $metric = $params['metric'] ?? '';
        $metricName = $params['metric_name'] ?? '';
        $hours = min((int) ($params['hours'] ?? 168), 8760);
        $targetPoints = min((int) ($params['data_points'] ?? 24), 48);

        // Reject any metric_name that could escape $rrdDir via path traversal
        // or directory separators. metric_name is used either as a literal
        // RRD filename component (custom case) or as part of a glob pattern
        // (processor, mempool) — both are sensitive to /, \, .. and NUL.
        // Legitimate metric identifiers (port ifNames, storage descriptions,
        // processor descriptions) never contain these characters.
        if ($metricName !== '' && preg_match('#(\.\.|/|\\\\|\x00)#', $metricName)) {
            return ['error' => 'Invalid metric_name: path separators and traversal sequences are not allowed.'];
        }

        // Resolve RRD file path
        $rrdFile = $this->resolveRrdFile($device, $rrdDir, $metric, $metricName);
        if (! $rrdFile) {
            return ['error' => "Could not find RRD file for metric={$metric}, name={$metricName} on {$device->hostname}"];
        }

        // Calculate resolution to get roughly targetPoints data points
        $seconds = $hours * 3600;
        $resolution = max(60, (int) ($seconds / $targetPoints));
        // Round to nearest RRD step multiple
        $resolution = (int) (ceil($resolution / 60) * 60);

        $startTime = '-' . $seconds . 's';

        // Run rrdtool fetch
        $process = new Process([
            'rrdtool', 'fetch', $rrdFile, 'AVERAGE',
            '--start', $startTime,
            '--resolution', (string) $resolution,
        ]);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            return ['error' => 'rrdtool fetch failed: ' . $process->getErrorOutput()];
        }

        $output = $process->getOutput();

        return $this->parseRrdOutput($output, $device->hostname, $metric, $metricName, $hours, $targetPoints);
    }

    private function resolveRrdFile(Device $device, string $rrdDir, string $metric, string $metricName): ?string
    {
        switch ($metric) {
            case 'storage':
                // Find storage RRD by description
                $storage = Storage::where('device_id', $device->device_id)
                    ->where('storage_descr', 'like', '%' . $metricName . '%')
                    ->first();
                if ($storage) {
                    // Try common naming patterns
                    $patterns = [
                        $rrdDir . '/storage-hrstorage-' . str_replace(['/', ' '], ['_', '_'], $storage->storage_descr) . '.rrd',
                        $rrdDir . '/storage--' . str_replace(['/', ' '], ['_', '_'], $storage->storage_descr) . '.rrd',
                    ];
                    foreach ($patterns as $p) {
                        if (file_exists($p)) {
                            return $p;
                        }
                    }
                    // Glob fallback
                    $sanitized = str_replace('/', '_', $storage->storage_descr);
                    $matches = glob($rrdDir . '/storage*' . $sanitized . '*.rrd');
                    if (! empty($matches)) {
                        return $matches[0];
                    }
                }

                return null;

            case 'port':
                // Find port RRD by ifName
                $port = Port::where('device_id', $device->device_id)
                    ->where('deleted', 0)
                    ->where(function ($q) use ($metricName): void {
                        $q->where('ifName', $metricName)
                          ->orWhere('ifDescr', $metricName)
                          ->orWhere('ifName', 'like', '%' . $metricName . '%');
                    })
                    ->first();
                if ($port) {
                    $file = $rrdDir . '/port-id' . $port->port_id . '.rrd';
                    if (file_exists($file)) {
                        return $file;
                    }
                }

                return null;

            case 'processor':
                $matches = glob($rrdDir . '/processor-*' . str_replace(' ', '*', $metricName) . '*.rrd');

                return ! empty($matches) ? $matches[0] : null;

            case 'mempool':
                $matches = glob($rrdDir . '/mempool-*' . str_replace(' ', '*', $metricName) . '*.rrd');

                return ! empty($matches) ? $matches[0] : null;

            case 'custom':
                $file = $rrdDir . '/' . $metricName . '.rrd';

                return file_exists($file) ? $file : null;

            default:
                return null;
        }
    }

    private function parseRrdOutput(string $output, string $hostname, string $metric, string $metricName, int $hours, int $targetPoints = 24): array
    {
        $lines = explode("\n", trim($output));
        if (empty($lines)) {
            return ['error' => 'No data returned from rrdtool'];
        }

        // First line is header with datasource names
        $header = preg_split('/\s+/', trim($lines[0]));

        $dataPoints = [];
        $validPoints = 0;

        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 2) {
                continue;
            }

            $timestamp = (int) rtrim($parts[0], ':');
            $values = [];
            $allNan = true;

            for ($j = 1; $j < count($parts); $j++) {
                $dsName = $header[$j - 1] ?? "ds{$j}";
                $val = $parts[$j];
                if ($val === '-nan' || $val === 'nan' || $val === 'NaN') {
                    $values[$dsName] = null;
                } else {
                    $values[$dsName] = (float) $val;
                    $allNan = false;
                }
            }

            if (! $allNan) {
                $dataPoints[] = [
                    'timestamp' => $timestamp,
                    'time' => date('Y-m-d H:i', $timestamp),
                    'values' => $values,
                ];
                $validPoints++;
            }
        }

        // Add summary statistics
        $summary = [];
        foreach ($header as $dsName) {
            $vals = array_filter(
                array_map(fn ($dp) => $dp['values'][$dsName] ?? null, $dataPoints),
                fn ($v) => $v !== null
            );
            if (! empty($vals)) {
                $summary[$dsName] = [
                    'min' => round(min($vals), 2),
                    'max' => round(max($vals), 2),
                    'avg' => round(array_sum($vals) / count($vals), 2),
                    'current' => round(end($vals), 2),
                    'trend' => $this->calculateTrend($vals),
                ];
            }
        }

        // Downsample data points if too many — keep only evenly spaced samples
        $maxPoints = min($targetPoints, 48);
        if (count($dataPoints) > $maxPoints) {
            $step = count($dataPoints) / $maxPoints;
            $sampled = [];
            for ($s = 0; $s < $maxPoints; $s++) {
                $sampled[] = $dataPoints[(int) ($s * $step)];
            }
            $dataPoints = $sampled;
        }

        // Round large values for readability and token savings
        foreach ($dataPoints as &$dp) {
            foreach ($dp['values'] as &$v) {
                if ($v !== null && abs($v) > 1000000) {
                    $v = round($v, -3); // Round to nearest thousand
                }
            }
        }
        unset($dp, $v);

        return [
            'device' => $hostname,
            'metric' => $metric,
            'metric_name' => $metricName,
            'period_hours' => $hours,
            'data_points' => count($dataPoints),
            'datasources' => $header,
            'summary' => $summary,
            'data' => $dataPoints,
        ];
    }

    /**
     * Simple linear trend: positive = growing, negative = shrinking.
     * Returns rate of change per hour.
     */
    private function calculateTrend(array $values): ?float
    {
        $n = count($values);
        if ($n < 2) {
            return null;
        }

        // Simple linear regression
        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumXX = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumX += $i;
            $sumY += $values[$i];
            $sumXY += $i * $values[$i];
            $sumXX += $i * $i;
        }

        $denominator = $n * $sumXX - $sumX * $sumX;
        if ($denominator == 0) {
            return 0.0;
        }

        $slope = ($n * $sumXY - $sumX * $sumY) / $denominator;

        return round($slope, 4);
    }
}
