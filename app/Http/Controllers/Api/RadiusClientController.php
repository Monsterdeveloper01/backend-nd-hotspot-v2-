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

    public function getLogs()
    {
        return \App\Models\RadiusLog::orderBy('created_at', 'desc')->limit(50)->get();
    }
}
