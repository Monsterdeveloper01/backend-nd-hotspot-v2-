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

    public function index()
    {
        $olts = OltConfig::withCount('nodes')->get();
        return response()->json($olts);
    }

    public function storeOlt(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'ip_address' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string',
            'type' => 'required|string'
        ]);

        $olt = OltConfig::create($validated);
        return response()->json($olt);
    }

    public function nodes($oltId)
    {
        $nodes = OnuNode::where('olt_id', $oltId)->get();
        return response()->json($nodes);
    }

    public function sync($oltId)
    {
        $olt = OltConfig::findOrFail($oltId);
        $this->oltService->syncOnus($olt);
        return response()->json(['message' => 'Synchronization complete']);
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
        $success = $this->oltService->rebootOnu($node);
        return response()->json(['success' => $success]);
    }
}
