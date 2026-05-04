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
            'timeout' => 15, // Memberi nafas lebih panjang untuk 160+ user
        ];
    }

    private function connect()
    {
        if (!$this->client) {
            $this->client = new RouterosAPI();
            $this->client->timeout = $this->config['timeout'];
            if (!$this->client->connect($this->config['host'], $this->config['user'], $this->config['pass'], $this->config['port'])) {
                Log::error("Gagal terhubung ke MikroTik: " . $this->config['host']);
                $this->client = null;
                return false;
            }
        }
        return true;
    }

    public function getAllHotspotUsers()
    {
        if (!$this->connect()) return [];
        $users = $this->client->comm('/ip/hotspot/user/print');
        return is_array($users) ? $users : [];
    }

    public function getActiveUsers()
    {
        if (!$this->connect()) return [];
        $active = $this->client->comm('/ip/hotspot/active/print');
        return is_array($active) ? $active : [];
    }

    public function setUserStatus($username, $enabled)
    {
        if (!$this->connect()) return false;

        // Cari user by name
        $users = $this->client->comm('/ip/hotspot/user/print', [
            '?name' => $username
        ]);

        if (empty($users)) return false;

        $id = $users[0]['.id'];
        $this->client->comm('/ip/hotspot/user/set', [
            '.id' => $id,
            'disabled' => $enabled ? 'no' : 'yes'
        ]);

        if (!$enabled) {
            $this->kickUser($username);
        }

        return true;
    }

    public function kickUser($username)
    {
        if (!$this->connect()) return false;
        $active = $this->client->comm('/ip/hotspot/active/print', [
            '?name' => $username
        ]);

        foreach ($active as $a) {
            $this->client->comm('/ip/hotspot/active/remove', [
                '.id' => $a['.id']
            ]);
        }
        return true;
    }

    public function createUser($data)
    {
        if (!$this->connect()) return false;
        return $this->client->comm('/ip/hotspot/user/add', [
            'name' => $data['username'],
            'password' => $data['password'] ?? '',
            'profile' => $data['profile'] ?? 'default',
            'comment' => $data['comment'] ?? 'Created by ND Hotspot'
        ]);
    }
    
    public function deleteUser($username)
    {
        if (!$this->connect()) return false;
        $users = $this->client->comm('/ip/hotspot/user/print', [
            '?name' => $username
        ]);
        if (empty($users)) return false;

        return $this->client->comm('/ip/hotspot/user/remove', [
            '.id' => $users[0]['.id']
        ]);
    }
}

/**
 * RouterosAPI Standalone Class
 * Dioptimasi untuk ROS v7 (Paging & Sentence Handling)
 */
class RouterosAPI {
    var $connected = false;
    var $socket;
    var $timeout = 15;

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
        $parsed = array();
        $current = null;
        $done = false;
        while (!$done) {
            $length = $this->decode_length();
            if ($length > 0) {
                $line = fread($this->socket, $length);
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
            } else {
                break; // Socket closed
            }
        }
        return $parse ? $parsed : array_map(function($a){return $a['type']??$a;}, $parsed);
    }

    function encode_length($length) {
        if ($length < 0x80) fwrite($this->socket, chr($length));
        elseif ($length < 0x4000) fwrite($this->socket, chr(($length >> 8) | 0x80) . chr($length & 0xff));
        elseif ($length < 0x200000) fwrite($this->socket, chr(($length >> 16) | 0xc0) . chr(($length >> 8) & 0xff) . chr($length & 0xff));
        elseif ($length < 0x10000000) fwrite($this->socket, chr(($length >> 24) | 0xe0) . chr(($length >> 16) & 0xff) . chr(($length >> 8) & 0xff) . chr($length & 0xff));
        else fwrite($this->socket, chr(0xf0) . chr(($length >> 24) & 0xff) . chr(($length >> 16) & 0xff) . chr(($length >> 8) & 0xff) . chr($length & 0xff));
    }

    function decode_length() {
        $c = fread($this->socket, 1);
        if ($c === false || $c === "") return -1;
        $byte = ord($c);
        if (($byte & 0x80) == 0x00) return $byte;
        if (($byte & 0xc0) == 0x80) return (($byte & 0x3f) << 8) + ord(fread($this->socket, 1));
        if (($byte & 0xe0) == 0xc0) return (($byte & 0x1f) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        if (($byte & 0xf0) == 0xe0) return (($byte & 0x0f) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        return 0;
    }
}
