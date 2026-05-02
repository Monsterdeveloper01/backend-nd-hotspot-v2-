<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RadiusClient;
use Illuminate\Http\Request;

class RadiusClientController extends Controller
{
    public function index()
    {
        return RadiusClient::all();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required',
            'ip_address' => 'required',
            'shared_secret' => 'required',
        ]);

        return RadiusClient::create($data);
    }

    public function destroy($id)
    {
        RadiusClient::destroy($id);
        return response()->json(['message' => 'Client removed']);
    }

    public function status()
    {
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $isRunning = false;
        
        if ($socket) {
            // If we CANNOT bind to 1812, it means the RADIUS Server is already running and holding the port!
            $bound = @socket_bind($socket, '0.0.0.0', 1812);
            if (!$bound) {
                $isRunning = true;
            }
            socket_close($socket);
        }
        
        return response()->json([
            'status' => $isRunning ? 'Online' : 'Offline'
        ]);
    }

    public function getLogs()
    {
        return \App\Models\RadiusLog::orderBy('created_at', 'desc')->limit(50)->get();
    }
}
