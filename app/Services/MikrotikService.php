<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class MikrotikService
{
    protected $client;
    protected $config;

    public function __construct()
    {
        $this->config = [
            'host' => env('MIKROTIK_HOST', '101.255.208.150'),
            'user' => env('MIKROTIK_USERNAME', 'admin'),
            'pass' => env('MIKROTIK_PASSWORD', 'karambia1686'),
            'port' => (int)env('MIKROTIK_PORT', 8728),
            'timeout' => 10, // Ditingkatkan untuk stabilitas
        ];
    }

    private function getClient()
    {
        if (!$this->client) {
            $this->client = new RouterosAPI();
            $this->client->timeout = $this->config['timeout'];
            if (!$this->client->connect($this->config['host'], $this->config['user'], $this->config['pass'], $this->config['port'])) {
                $this->client = null;
            }
        }
        return $this->client;
    }

    public function getAllHotspotUsers()
    {
        $client = $this->getClient();
        if (!$client) return [];

        // Mengambil data secara bulk tanpa proplist agar kompatibel dengan v7 jika data tidak terlalu besar
        $users = $client->comm('/ip/hotspot/user/print');
        return is_array($users) ? $users : [];
    }

    public function getActiveUsers()
    {
        $client = $this->getClient();
        if (!$client) return [];

        $active = $client->comm('/ip/hotspot/active/print');
        return is_array($active) ? $active : [];
    }

    public function setUserStatus($username, $enabled)
    {
        $client = $this->getClient();
        if (!$client) return false;

        $users = $client->comm('/ip/hotspot/user/print', [
            '?name' => $username
        ]);

        if (empty($users)) return false;

        $client->comm('/ip/hotspot/user/set', [
            '.id' => $users[0]['.id'],
            'disabled' => $enabled ? 'no' : 'yes'
        ]);

        if (!$enabled) {
            $this->kickUser($username);
        }

        return true;
    }

    public function kickUser($username)
    {
        $client = $this->getClient();
        if (!$client) return false;

        $active = $client->comm('/ip/hotspot/active/print', [
            '?name' => $username
        ]);

        foreach ($active as $a) {
            $client->comm('/ip/hotspot/active/remove', [
                '.id' => $a['.id']
            ]);
        }
        return true;
    }

    public function createUser($data)
    {
        $client = $this->getClient();
        if (!$client) return false;

        return $client->comm('/ip/hotspot/user/add', [
            'name' => $data['username'],
            'password' => $data['password'] ?? '',
            'profile' => $data['profile'] ?? 'default',
            'comment' => $data['comment'] ?? 'Created by ND Hotspot'
        ]);
    }
}

/**
 * Class RouterosAPI
 * Versi stabil untuk ROS v6/v7
 */
class RouterosAPI {
    var $connected = false;
    var $socket;
    var $timeout = 10;
    var $attempts = 1;

    function connect($host, $user, $pass, $port = 8728) {
        $this->socket = @fsockopen($host, $port, $errNo, $errStr, $this->timeout);
        if ($this->socket) {
            socket_set_timeout($this->socket, $this->timeout);
            if ($this->login($user, $pass)) {
                $this->connected = true;
                return true;
            }
            fclose($this->socket);
        }
        return false;
    }

    function login($user, $pass) {
        $this->write('/login', false);
        $this->write('=name=' . $user, false);
        $this->write('=password=' . $pass);
        $res = $this->read(false);
        if (isset($res[0]) && $res[0] == '!done') {
            if (isset($res[1]) && strpos($res[1], '=ret=') === 0) {
                $challenge = substr($res[1], 5);
                $md5 = md5(chr(0) . $pass . pack('H*', $challenge));
                $this->write('/login', false);
                $this->write('=name=' . $user, false);
                $this->write('=response=00' . $md5);
                $res2 = $this->read(false);
                return (isset($res2[0]) && $res2[0] == '!done');
            }
            return true;
        }
        return false;
    }

    function comm($com, $args = array()) {
        $this->write($com, false);
        foreach ($args as $key => $value) {
            $this->write('=' . $key . '=' . $value, false);
        }
        $this->write($com, true);
        return $this->read();
    }

    function write($command, $param2 = true) {
        $com = trim($command);
        $this->encode_length(strlen($com));
        fwrite($this->socket, $com);
        if ($param2) fwrite($this->socket, chr(0));
    }

    function read($parse = true) {
        $res = array();
        $parsed = array();
        $current = null;
        $done = false;
        while (!$done) {
            $length = $this->decode_length();
            if ($length > 0) {
                $line = fread($this->socket, $length);
                $res[] = $line;
                if ($line == '!re' || $line == '!trap' || $line == '!done') {
                    if ($line == '!done') $done = true;
                    $current = array('type' => $line);
                    $parsed[] = &$current;
                } elseif (substr($line, 0, 1) == '=') {
                    $pos = strpos($line, '=', 1);
                    if ($pos !== false) {
                        $current[substr($line, 1, $pos - 1)] = substr($line, $pos + 1);
                    }
                }
            } elseif ($length == 0) {
                if ($done) break;
            }
        }
        return $parse ? $parsed : $res;
    }

    function encode_length($length) {
        if ($length < 0x80) fwrite($this->socket, chr($length));
        elseif ($length < 0x4000) fwrite($this->socket, chr(($length >> 8) | 0x80) . chr($length & 0xff));
        elseif ($length < 0x200000) fwrite($this->socket, chr(($length >> 16) | 0xc0) . chr(($length >> 8) & 0xff) . chr($length & 0xff));
        elseif ($length < 0x10000000) fwrite($this->socket, chr(($length >> 24) | 0xe0) . chr(($length >> 16) & 0xff) . chr(($length >> 8) & 0xff) . chr($length & 0xff));
        else fwrite($this->socket, chr(0xf0) . chr(($length >> 24) & 0xff) . chr(($length >> 16) & 0xff) . chr(($length >> 8) & 0xff) . chr($length & 0xff));
    }

    function decode_length() {
        $byte = ord(fread($this->socket, 1));
        if (($byte & 0x80) == 0x00) return $byte;
        if (($byte & 0xc0) == 0x80) return (($byte & 0x3f) << 8) + ord(fread($this->socket, 1));
        if (($byte & 0xe0) == 0xc0) return (($byte & 0x1f) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        if (($byte & 0xf0) == 0xe0) return (($byte & 0x0f) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        return 0;
    }
}
