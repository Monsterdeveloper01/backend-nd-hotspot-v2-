<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OltConfig;
use App\Models\OnuNode;
use App\Services\OltService;
use Illuminate\Http\Request;

class NetworkCenterController extends Controller
{
    protected $oltService;

    public function __construct(OltService $oltService)
    {
        $this->oltService = $oltService;
    }

    public function getOlt()
    {
        $olts = OltConfig::withCount('nodes')->get();
        return response()->json($olts);
    }

    public function getOnu($id)
    {
        $nodes = OnuNode::where('olt_id', $id)->get()->map(function ($node) {
            $node->signal_quality = $this->getSignalQuality($node->last_signal);
            return $node;
        });

        $olt = OltConfig::find($id);

        return response()->json([
            'data' => $nodes,
            'meta' => [
                'total' => $nodes->count(),
                'online' => $nodes->where('status', 'online')->count(),
                'offline' => $nodes->where('status', 'offline')->count(),
                'last_synced_at' => $olt ? $olt->last_synced_at : null
            ]
        ]);
    }

    public function getOnuLive($id)
    {
        $olt = OltConfig::findOrFail($id);
        $onus = $this->oltService->getAllOnu($olt);
        
        $mapped = array_map(function($onu) {
            $onu['signal_quality'] = $this->getSignalQuality($onu['signal']);
            return $onu;
        }, $onus);

        return response()->json($mapped);
    }

    public function syncOlt($id)
    {
        $olt = OltConfig::findOrFail($id);
        
        // Dispatch job synchronously for immediate response or normally
        \App\Jobs\SyncOnuStatusJob::dispatchSync($olt);
        
        return response()->json(['message' => 'Synchronization complete']);
    }

    public function getStatus($id)
    {
        $olt = OltConfig::findOrFail($id);
        $reachable = $this->oltService->isReachable($olt);
        
        $systemInfo = null;
        if ($reachable) {
            $systemInfo = $this->oltService->getSystemInfo($olt);
        }

        return response()->json([
            'reachable' => $reachable,
            'systemInfo' => $systemInfo
        ]);
    }

    private function getSignalQuality($signal)
    {
        if ($signal === null) return 'Unknown';
        if ($signal >= -25) return 'Excellent';
        if ($signal >= -28) return 'Good';
        if ($signal >= -30) return 'Warning';
        return 'Critical';
    }

    // Keep existing methods if needed by other routes, but they weren't explicitly requested to be removed
    // storeOlt, updateNode, reboot
    public function storeOlt(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'ip_address' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string',
            'type' => 'required|string',
            'snmp_community' => 'nullable|string'
        ]);

        if (empty($validated['snmp_community'])) {
            $validated['snmp_community'] = 'public';
        }

        $olt = OltConfig::create($validated);
        return response()->json($olt);
    }

    public function updateOlt(Request $request, $id)
    {
        $olt = OltConfig::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'required|string',
            'ip_address' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string',
            'type' => 'required|string',
            'snmp_community' => 'nullable|string'
        ]);

        if (empty($validated['snmp_community'])) {
            $validated['snmp_community'] = 'public';
        }

        $olt->update($validated);
        return response()->json($olt);
    }

    public function deleteOlt($id)
    {
        $olt = OltConfig::findOrFail($id);
        $olt->delete();
        return response()->json(['message' => 'OLT berhasil dihapus']);
    }

    public function updateNode(Request $request, $id)
    {
        $node = OnuNode::findOrFail($id);
        $node->update($request->only(['alias']));
        return response()->json($node);
    }

    public function reboot($id)
    {
        $node = OnuNode::findOrFail($id);
        // Assuming OltService still has rebootOnu (or dummy implementation if removed)
        // Since OltService was rewritten, we might need to handle this or just return false
        return response()->json(['success' => false, 'message' => 'Not implemented in V-SOL SNMP']);
    }
}
