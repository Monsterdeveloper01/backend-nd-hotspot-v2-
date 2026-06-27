<?php

namespace App\Services;

use App\Models\OltConfig;
use App\Models\OnuNode;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OltService
{
    protected $timeout;
    protected $retries;

    public function __construct()
    {
        if (!function_exists('snmp2_walk')) {
            throw new RuntimeException('PHP SNMP extension tidak aktif.');
        }

        $this->timeout = config('snmp.timeout', 3000000);
        $this->retries = config('snmp.retries', 1);
    }

    public function snmpWalk($ip, $community, $oid)
    {
        snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
        $result = @snmp2_real_walk($ip, $community, $oid, $this->timeout, $this->retries);
        return $result !== false ? $result : [];
    }

    public function snmpGet($ip, $community, $oid)
    {
        $result = @snmp2_get($ip, $community, $oid, $this->timeout, $this->retries);
        if ($result !== false) {
            $parts = explode(':', $result, 2);
            return isset($parts[1]) ? trim(str_replace('"', '', $parts[1])) : trim($result);
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
        // V-SOL specific OIDs
        $snOid = '.1.3.6.1.4.1.37582.89.53.1.1.1.1.2'; 
        $statusOid = '.1.3.6.1.4.1.37582.89.53.1.1.1.1.5';
        $signalOid = '.1.3.6.1.4.1.37582.89.53.1.1.1.1.8';

        $snData = $this->snmpWalk($olt->ip_address, $olt->snmp_community, $snOid);
        $statusData = $this->snmpWalk($olt->ip_address, $olt->snmp_community, $statusOid);
        $signalData = $this->snmpWalk($olt->ip_address, $olt->snmp_community, $signalOid);

        foreach ($snData as $oid => $val) {
            $parts = explode('.', $oid);
            $index = end($parts);
            
            $onus[$index] = [
                'onu_index' => $index,
                'serial_number' => $this->cleanSnmpValue($val),
                'status' => 'offline',
                'signal' => null
            ];
        }

        foreach ($statusData as $oid => $val) {
            $parts = explode('.', $oid);
            $index = end($parts);
            if (isset($onus[$index])) {
                $onus[$index]['status'] = $this->parseOnuStatus($this->cleanSnmpValue($val));
            }
        }

        foreach ($signalData as $oid => $val) {
            $parts = explode('.', $oid);
            $index = end($parts);
            if (isset($onus[$index])) {
                $onus[$index]['signal'] = $this->convertSignal($this->cleanSnmpValue($val));
            }
        }

        return array_values($onus);
    }

    public function getOnuStatus(OltConfig $olt, $onuIndex)
    {
        $statusOid = '.1.3.6.1.4.1.37582.89.53.1.1.1.1.5.' . $onuIndex;
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
        if ($value === 1) return 'online';
        return 'offline';
    }
    
    private function cleanSnmpValue($val)
    {
        $parts = explode(':', $val, 2);
        return isset($parts[1]) ? trim(str_replace('"', '', $parts[1])) : trim($val);
    }
}
