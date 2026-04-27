<?php

namespace App\Services;

use App\Models\OltConfig;
use App\Models\OnuNode;
use Illuminate\Support\Facades\Log;

class OltService
{
    /**
     * Fetch all ONUs from OLT and sync to local database
     */
    public function syncOnus(OltConfig $olt)
    {
        // This is a placeholder for real OLT communication
        // Real implementation would use SNMP (snmpwalk) or Telnet/SSH CLI
        
        // Mocking behavior for development
        $mockOnus = [
            ['index' => '1/1/1:1', 'sn' => 'GPON00A1B2C3', 'ip' => '192.168.1.10', 'signal' => -18.5, 'temp' => 42.5, 'status' => 'online'],
            ['index' => '1/1/1:2', 'sn' => 'GPON00D4E5F6', 'ip' => '192.168.1.11', 'signal' => -22.1, 'temp' => 45.0, 'status' => 'online'],
            ['index' => '1/1/2:1', 'sn' => 'GPON007890AB', 'ip' => '192.168.1.12', 'signal' => -31.2, 'temp' => 40.2, 'status' => 'online'], // Redaman tinggi
            ['index' => '1/1/2:2', 'sn' => 'GPON00CDEFGH', 'ip' => null, 'signal' => 0, 'temp' => 0, 'status' => 'offline'],
        ];

        foreach ($mockOnus as $data) {
            OnuNode::updateOrCreate(
                ['olt_id' => $olt->id, 'onu_index' => $data['index']],
                [
                    'serial_number' => $data['sn'],
                    'ip_address' => $data['ip'],
                    'last_signal' => $data['signal'],
                    'last_temp' => $data['temp'],
                    'status' => $data['status'],
                    'last_check' => now(),
                    'client_count' => rand(1, 8)
                ]
            );
        }

        return true;
    }

    /**
     * Reboot a specific ONU via OLT CLI
     */
    public function rebootOnu(OnuNode $node)
    {
        $olt = $node->olt;
        Log::info("Sending reboot command to OLT {$olt->name} for ONU {$node->onu_index}");
        
        // Mock command execution
        // Example for ZTE: onu reboot slot <slot> pon <pon> onu <onu>
        // Example for Global/VSOL: onu reboot <index>
        
        return true;
    }

    /**
     * Get real-time signal level (Rx Power) from OLT via SNMP
     */
    public function getSignal(OnuNode $node)
    {
        // Mock return
        return rand(-180, -320) / 10;
    }
}
