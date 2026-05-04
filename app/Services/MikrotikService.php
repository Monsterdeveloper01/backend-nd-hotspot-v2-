<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Mikrotik Service - Powered by PEAR2-style API logic
 * Stabil untuk ROS v6 dan v7
 */
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
            'timeout' => 15,
        ];
    }

    public function connect()
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
}

/**
 * RouterosAPI - THE OFFICIAL WIKI VERSION (MOST STABLE)
 */
class RouterosAPI {
    var $debug = false;
    var $connected = false;
    var $port = 8728;
    var $timeout = 10;
    var $attempts = 1;
    var $delay = 0;
    var $socket;
    var $error_no;
    var $error_str;

    function connect($host, $user, $pass, $port = 8728) {
        $this->host = $host;
        $this->user = $user;
        $this->pass = $pass;
        $this->port = $port;
        $this->socket = @fsockopen($this->host, $this->port, $this->error_no, $this->error_str, $this->timeout);
        if ($this->socket) {
            socket_set_timeout($this->socket, $this->timeout);
            if ($this->login($this->user, $this->pass)) {
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
                if (isset($res2[0]) && $res2[0] == '!done') return true;
            } else {
                return true;
            }
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
        if ($command) {
            $data = explode("\n", $command);
            foreach ($data as $com) {
                $com = trim($com);
                $this->encode_length(strlen($com));
                fwrite($this->socket, $com);
            }
            if ($param2) fwrite($this->socket, chr(0));
            return true;
        }
        return false;
    }

    function read($parse = true) {
        $res = array();
        while (true) {
            $length = $this->decode_length();
            if ($length > 0) {
                $res[] = fread($this->socket, $length);
            } elseif ($length == 0) {
                break;
            }
        }

        if ($parse) {
            $parsed = array();
            $current = null;
            foreach ($res as $line) {
                if ($line == '!re' || $line == '!trap' || $line == '!done') {
                    $current = array('type' => $line);
                    $parsed[] = &$current;
                } elseif (substr($line, 0, 1) == '=') {
                    $pos = strpos($line, '=', 1);
                    $current[substr($line, 1, $pos - 1)] = substr($line, $pos + 1);
                }
            }
            return $parsed;
        } else {
            return $res;
        }
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
