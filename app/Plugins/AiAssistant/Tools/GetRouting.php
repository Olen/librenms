<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\BgpPeer;
use App\Models\User;

class GetRouting extends AbstractAiTool
{
    public function name(): string
    {
        return 'get_routing';
    }

    public function description(): string
    {
        return 'Returns BGP peer routing information including session state, AS numbers, and update counts. Useful for diagnosing BGP session issues.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => [
                    'type' => 'integer',
                    'description' => 'Filter BGP peers for a specific device ID.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['established', 'down', 'all'],
                    'description' => 'Filter by BGP session state. "down" returns non-established sessions.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of BGP peers to return (default 50, max 200).',
                    'default' => 50,
                    'maximum' => 200,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $query = BgpPeer::query()->with('device:device_id,hostname');

        if ($user !== null) {
            // @phpstan-ignore method.notFound
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (! empty($params['device_id'])) {
            $query->where('device_id', (int) $params['device_id']);
        }

        $status = $params['status'] ?? 'all';
        if ($status === 'established') {
            $query->where('bgpPeerState', 'established');
        } elseif ($status === 'down') {
            $query->where('bgpPeerState', '!=', 'established');
        }

        $limit = min((int) ($params['limit'] ?? 50), 200);
        $peers = $query->limit($limit)->get();

        return [
            'count' => $peers->count(),
            'bgp_peers' => $peers->map(fn ($p) => [
                'bgpPeer_id' => $p->bgpPeer_id,
                'device_id' => $p->device_id,
                'hostname' => $p->device?->hostname,
                'local_addr' => $p->bgpLocalAddr,
                'remote_addr' => $p->bgpPeerIdentifier,
                'remote_as' => $p->bgpPeerRemoteAs,
                'description' => $p->bgpPeerDescr,
                'as_text' => $p->astext,
                'state' => $p->bgpPeerState,
                'admin_status' => $p->bgpPeerAdminStatus,
                'established_time' => $p->bgpPeerFsmEstablishedTime,
                'in_updates' => $p->bgpPeerInUpdates,
                'out_updates' => $p->bgpPeerOutUpdates,
            ])->values()->toArray(),
        ];
    }
}
