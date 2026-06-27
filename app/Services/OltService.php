<?php

namespace App\Services;

use App\Models\OltConfig;
use Illuminate\Support\Facades\Log;

class OltService
{
    protected $timeout;
    protected $retries;

    public function __construct()
    {
        // Timeout in config is in microseconds for snmp2_walk (e.g. 3000000 = 3s).
        // For shell_exec we convert it to seconds.
        $this->timeout = max(1, round(config('snmp.timeout', 3000000) / 1000000));
        $this->retries = config('snmp.retries', 1);
    }

    public function snmpWalk($ip, $community, $oid)
    {
        // -v2c: SNMP version 2c
        // -c: Community string
        // -t: Timeout in seconds
        // -r: Retries
        // -On: Output numeric OID
        // -Oq: Quick print (simplifies values, e.g. STRING: "value" becomes "value")
        $command = escapeshellcmd("snmpwalk -v2c -c {$community} -t {$this->timeout} -r {$this->retries} -On -Oq {$ip} {$oid}") . " 2>/dev/null";
        $output = shell_exec($command);
        
        $result = [];
        if ($output) {
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                if (strpos($line, ' ') !== false) {
                    list($key, $val) = explode(' ', $line, 2);
                    $result[trim($key)] = trim($val);
                }
            }
        }
        return $result;
    }

    public function snmpGet($ip, $community, $oid)
    {
        $command = escapeshellcmd("snmpget -v2c -c {$community} -t {$this->timeout} -r {$this->retries} -On -Oq {$ip} {$oid}") . " 2>/dev/null";
        $output = shell_exec($command);
        
        if ($output && strpos($output, ' ') !== false) {
            list($key, $val) = explode(' ', trim($output), 2);
            return trim(str_replace('"', '', $val));
        }
        return null;
    }

    public function isReachable(OltConfig $olt)
    {
        $sysDescr = $this->snmpGet($olt->ip_address, $olt->snmp_community, '.1.3.6.1.2.1.1.1.0');
        return !empty($sysDescr);
    }

    public function getSystemInfo(OltConfig $olt)
    {
        return [
            'sysDescr' => $this->snmpGet($olt->ip_address, $olt->snmp_community, '.1.3.6.1.2.1.1.1.0'),
            'sysUpTime' => $this->snmpGet($olt->ip_address, $olt->snmp_community, '.1.3.6.1.2.1.1.3.0'),
            'sysName' => $this->snmpGet($olt->ip_address, $olt->snmp_community, '.1.3.6.1.2.1.1.5.0'),
        ];
    }

    public function getAllOnu(OltConfig $olt)
    {
        $onus = [];
        
        // Detect OLT Type by checking sysObjectID (e.g. 37950 = C-DATA, 37582 = V-SOL)
        $sysObjectId = $this->snmpGet($olt->ip_address, $olt->snmp_community, '.1.3.6.1.2.1.1.2.0');
        
        if ($sysObjectId && strpos($sysObjectId, '37950') !== false) {
            // C-DATA GPON OIDs
            $snOid = '.1.3.6.1.4.1.37950.1.1.6.1.1.2.1.5.1'; 
            $statusOid = '.1.3.6.1.4.1.37950.1.1.6.1.1.1.1.5.1'; // Operational Status (3 = working)
            $signalOid = null; // Signal implementation for C-DATA pending
            $aliasOid = '.1.3.6.1.4.1.37950.1.1.6.1.1.3.1.8.1'; // Common C-DATA Alias OID
        } else {
            // Default V-SOL GPON OIDs
            $snOid = '.1.3.6.1.4.1.37582.89.53.1.1.1.1.2'; 
            $statusOid = '.1.3.6.1.4.1.37582.89.53.1.1.1.1.5';
            $signalOid = '.1.3.6.1.4.1.37582.89.53.1.1.1.1.8';
            $aliasOid = '.1.3.6.1.4.1.37582.89.53.1.1.1.1.14'; // V-SOL Description
        }

        $snData = $this->snmpWalk($olt->ip_address, $olt->snmp_community, $snOid);
        $statusData = $this->snmpWalk($olt->ip_address, $olt->snmp_community, $statusOid);
        $signalData = $signalOid ? $this->snmpWalk($olt->ip_address, $olt->snmp_community, $signalOid) : [];
        $aliasData = $aliasOid ? $this->snmpWalk($olt->ip_address, $olt->snmp_community, $aliasOid) : [];

        foreach ($snData as $oid => $val) {
            $parts = explode('.', trim($oid, '.'));
            $index = end($parts);
            
            $valClean = $this->cleanSnmpValue($val);
            if (empty($valClean) || stripos($valClean, 'no such object') !== false || stripos($valClean, 'no such instance') !== false) {
                continue;
            }
            
            $onus[$index] = [
                'onu_index' => $index,
                'serial_number' => $valClean,
                'status' => 'offline',
                'signal' => null,
                'alias' => null
            ];
        }

        foreach ($aliasData as $oid => $val) {
            $parts = explode('.', trim($oid, '.'));
            $index = end($parts);
            if (isset($onus[$index])) {
                $valClean = $this->cleanSnmpValue($val);
                if (!empty($valClean) && stripos($valClean, 'no such object') === false) {
                    $onus[$index]['alias'] = $valClean;
                }
            }
        }

        foreach ($statusData as $oid => $val) {
            $parts = explode('.', trim($oid, '.'));
            $index = end($parts);
            if (isset($onus[$index])) {
                $onus[$index]['status'] = $this->parseOnuStatus($this->cleanSnmpValue($val));
            }
        }

        foreach ($signalData as $oid => $val) {
            $parts = explode('.', trim($oid, '.'));
            $index = end($parts);
            if (isset($onus[$index])) {
                $onus[$index]['signal'] = $this->convertSignal($this->cleanSnmpValue($val));
            }
        }

        return array_values($onus);
    }

    public function getOnuStatus(OltConfig $olt, $onuIndex)
    {
        $sysObjectId = $this->snmpGet($olt->ip_address, $olt->snmp_community, '.1.3.6.1.2.1.1.2.0');
        if ($sysObjectId && strpos($sysObjectId, '37950') !== false) {
            $statusOid = '.1.3.6.1.4.1.37950.1.1.6.1.1.1.1.5.1.' . $onuIndex;
        } else {
            $statusOid = '.1.3.6.1.4.1.37582.89.53.1.1.1.1.5.' . $onuIndex;
        }
        
        $val = $this->snmpGet($olt->ip_address, $olt->snmp_community, $statusOid);
        return $val ? $this->parseOnuStatus($val) : 'offline';
    }

    public function convertSignal($value)
    {
        $value = (int)$value;
        if ($value == 0 || $value == -2147483648 || $value == 65535) {
            return null;
        }
        return round($value / 100, 2);
    }

    public function parseOnuStatus($value)
    {
        $value = (int)$value;
        // V-SOL: 1 = online
        // C-DATA: 3 = working/online
        if ($value === 1 || $value === 3) return 'online';
        return 'offline';
    }
    
    private function cleanSnmpValue($val)
    {
        return trim(str_replace(['"', 'STRING:', 'INTEGER:'], '', $val));
    }
}
