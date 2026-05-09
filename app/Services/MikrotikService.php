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
            'host'     => env('MIKROTIK_HOST', '101.255.208.150'),
            'user'     => env('MIKROTIK_USERNAME', 'admin'),
            'pass'     => env('MIKROTIK_PASSWORD', 'karambia1686'),
            'port'     => (int) env('MIKROTIK_PORT', 8728),
            'timeout'  => 15,
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

    public function disconnect()
    {
        if ($this->client) {
            $this->client->disconnect();
            $this->client = null;
        }
    }

    public function getAllHotspotUsers()
    {
        if (!$this->connect()) return [];
        $users = $this->client->comm('/ip/hotspot/user/print');
        $this->disconnect();
        return is_array($users) ? $users : [];
    }

    public function getActiveUsers()
    {
        if (!$this->connect()) return [];
        $active = $this->client->comm('/ip/hotspot/active/print');
        $this->disconnect();
        return is_array($active) ? $active : [];
    }

public function setUserStatus($username, $enabled)
    {
        if (!$this->connect()) {
            Log::error("Mikrotik: Gagal koneksi saat mencoba " . ($enabled ? "enable" : "disable") . " user {$username}");
            return false;
        }

        $username = trim($username);
        Log::info("Mikrotik: Mengubah status user {$username} ke " . ($enabled ? "ENABLED" : "DISABLED"));

        // 1. Coba cari langsung ke MikroTik
        $users = $this->client->comm('/ip/hotspot/user/print', [
            '?name' => $username
        ]);

        // Cek apakah MikroTik mengembalikan error (!trap) dari pencarian pertama
        if (isset($users['message'])) {
            Log::warning("Mikrotik API Error (Pencarian 1): " . $users['message']);
            $users = []; // Kosongkan agar bisa pindah ke pencarian manual
        }

        // 2. Jika tidak ketemu (kosong atau error), cari secara menyeluruh (manual)
        if (empty($users) || !isset($users[0])) {
            $allUsers = $this->client->comm('/ip/hotspot/user/print');
            
            if (isset($allUsers['message'])) {
                Log::error("Mikrotik API Error (Pencarian Total): " . $allUsers['message']);
                $this->disconnect();
                return false;
            }

            $searchName = strtolower($username);
            $users = [];
            
            // Perulangan untuk mencocokkan nama dengan aman
            if (is_array($allUsers)) {
                foreach ($allUsers as $u) {
                    if (strtolower(trim($u['name'] ?? '')) === $searchName) {
                        $users = [$u];
                        break;
                    }
                }
            }
        }

        // Validasi final sebelum mengambil ID
        if (empty($users) || !isset($users[0])) {
            Log::warning("Mikrotik: User '{$username}' tidak ditemukan di router saat ingin di-" . ($enabled ? "enable" : "disable"));
            $this->disconnect();
            return false;
        }

        $id = $users[0]['.id'] ?? $users[0]['id'] ?? null;
        if (!$id) {
            Log::error("Mikrotik: ID user {$username} tidak valid atau tidak ditemukan dalam struktur data API.", ['data' => $users[0]]);
            $this->disconnect();
            return false;
        }

        // 3. Eksekusi perubahan status
        $response = $this->client->comm('/ip/hotspot/user/set', [
            '.id' => $id,
            'disabled' => $enabled ? 'no' : 'yes'
        ]);

        // Cek apakah perintah pengubahan status ditolak router
        if (isset($response['type']) && $response['type'] === '!trap') {
            Log::error("Mikrotik API Error (Set Status Gagal): " . ($response['message'] ?? 'Unknown Error'));
            $this->disconnect();
            return false;
        }

        Log::info("Mikrotik: Proses set status untuk {$username} (ID: {$id}) berhasil.");

        // Putus koneksi sebelum menjalankan fungsi lain untuk menghindari tabrakan socket
        $this->disconnect();

        // 4. Bersihkan Sesi Aktif & Cookies
        if ($enabled) {
            try {
                $this->clearUserActiveSessions($username);
                $this->clearUserCookies($username);
            } catch (\Throwable $th) {
                Log::warning("Sesi/cookie gagal dibersihkan untuk {$username}: " . $th->getMessage());
            }
        } else {
            try {
                $this->kickUser($username);
            } catch (\Throwable $th) {
                Log::warning("Gagal kick user {$username}: " . $th->getMessage());
            }
        }

        return true;
    }

    public function kickUser($username)
    {
        if (!$this->connect()) return false;
        
        // 1. Coba cari langsung
        $active = $this->client->comm('/ip/hotspot/active/print', [
            '?name' => $username
        ]);

        // 2. Jika tidak ketemu, cari manual (case-insensitive)
        if (empty($active)) {
            $allActive = $this->client->comm('/ip/hotspot/active/print');
            $searchName = strtolower(trim($username));
            foreach ($allActive as $a) {
                if (strtolower(trim($a['user'] ?? '')) === $searchName) {
                    $active[] = $a;
                }
            }
        }

        foreach ($active as $a) {
            if (isset($a['.id'])) {
                $this->client->comm('/ip/hotspot/active/remove', [
                    '.id' => $a['.id']
                ]);
            }
        }
        $this->disconnect();
        return true;
    }

    public function createUser($data)
    {
        if (!$this->connect()) return false;
        $result = $this->client->comm('/ip/hotspot/user/add', [
            'name'     => $data['username'],
            'password' => $data['password'] ?? '',
            'profile'  => $data['profile'] ?? 'default',
            'comment'  => $data['comment'] ?? 'Created by ND Hotspot'
        ]);
        $this->disconnect();
        return $result;
    }

    public function updateProfile($data)
    {
        return true;
    }

    public function removeHotspotUser($username)
    {
        if (!$this->connect()) return false;
        
        $username = trim($username);
        \Log::info("Mikrotik: Mencoba menghapus user {$username}");

        // 1. Cari user
        $users = $this->client->comm('/ip/hotspot/user/print', [
            '?name' => $username
        ]);

        if (empty($users) || !is_array($users) || !isset($users[0])) {
            $allUsers = $this->client->comm('/ip/hotspot/user/print');
            $searchName = strtolower($username);
            $users = [];
            if (is_array($allUsers)) {
                foreach ($allUsers as $u) {
                    if (strtolower(trim($u['name'] ?? '')) === $searchName) {
                        $users = [$u];
                        break;
                    }
                }
            }
        }

        if (empty($users)) {
            \Log::warning("Mikrotik: User {$username} tidak ditemukan saat penghapusan.");
            $this->disconnect();
            return false;
        }

        $success = true;
        foreach ($users as $u) {
            if (isset($u['.id'])) {
                $res = $this->client->comm('/ip/hotspot/user/remove', [
                    '.id' => $u['.id']
                ]);
                
                // Cek trap/error
                if (isset($res['type']) && $res['type'] === '!trap') {
                    \Log::error("Mikrotik: Gagal hapus user {$username}. Pesan: " . ($res['message'] ?? 'Unknown Error'));
                    $success = false;
                } else {
                    \Log::info("Mikrotik: User {$username} (ID: {$u['.id']}) berhasil dihapus.");
                }
            }
        }
        
        $this->disconnect();
        return $success;
    }

    public function clearUserActiveSessions($username)
    {
        return $this->kickUser($username);
    }

    public function clearUserCookies($username)
    {
        if (!$this->connect()) return false;
        $cookies = $this->client->comm('/ip/hotspot/cookie/print', [
            '?user' => $username
        ]);
        if (is_array($cookies)) {
            foreach ($cookies as $c) {
                if (isset($c['.id'])) {
                    $this->client->comm('/ip/hotspot/cookie/remove', [
                        '.id' => $c['.id']
                    ]);
                }
            }
        }
        $this->disconnect();
        return true;
    }

    public function deleteUser($username)
    {
        return $this->removeHotspotUser($username);
    }

    public function getHotspotUserDetailed($username)
    {
        if (!$this->connect()) return null;
        
        // 1. Ambil data dari /ip/hotspot/user
        $users = $this->client->comm('/ip/hotspot/user/print', [
            '?name' => $username
        ]);

        if (empty($users)) {
            $this->disconnect();
            return null;
        }

        $user = $users[0];

        // 2. Cek apakah sedang aktif (online)
        $active = $this->client->comm('/ip/hotspot/active/print', [
            '?user' => $username
        ]);

        $this->disconnect();

        return [
            'name' => $user['name'] ?? '',
            'profile' => $user['profile'] ?? '',
            'uptime' => $user['uptime'] ?? '0s',
            'limit_uptime' => $user['limit-uptime'] ?? 'unlimited',
            'comment' => $user['comment'] ?? '',
            'is_online' => !empty($active),
            'active_data' => !empty($active) ? $active[0] : null
        ];
    }
}

/**
 * RouterosAPI - FIXED VERSION
 */
/**
 * RouterosAPI - FIXED VERSION
 */
class RouterosAPI
{
    var $debug      = false;
    var $connected  = false;
    var $port       = 8728;
    var $timeout    = 10;
    var $attempts   = 1;
    var $delay      = 0;
    var $socket;
    var $error_no;
    var $error_str;

    // 🔧 FIX: Deklarasi properti agar tidak muncul warning Deprecated di PHP 8.2+
    var $host;
    var $user;
    var $pass;

    function connect($host, $user, $pass, $port = 8728)
    {
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

    function disconnect()
    {
        if ($this->socket) {
            fclose($this->socket);
        }
        $this->connected = false;
        $this->socket = null;
    }

    function login($user, $pass)
    {
        $this->write('/login', false);
        $this->write('=name=' . $user, false);
        $this->write('=password=' . $pass, true);  // ← true = kirim terminator
        $res = $this->read(false);

        if (isset($res[0]) && $res[0] == '!done') {
            if (isset($res[1]) && strpos($res[1], '=ret=') === 0) {
                // Pre-v6.43 challenge response
                $challenge = substr($res[1], 5);
                $md5 = md5(chr(0) . $pass . pack('H*', $challenge));

                $this->write('/login', false);
                $this->write('=name=' . $user, false);
                $this->write('=response=00' . $md5, true);
                $res2 = $this->read(false);

                if (isset($res2[0]) && $res2[0] == '!done') {
                    return true;
                }
                return false;
            }
            // Post v6.43 plain auth
            return true;
        }
        return false;
    }

    function comm($com, $args = array())
    {
        if (!$this->connected) return false;

        $this->write($com, false);

        foreach ($args as $key => $value) {
            $this->write('=' . $key . '=' . $value, false);
        }

        // Kirim terminator untuk menandakan end of command
        fwrite($this->socket, chr(0));

        return $this->read();
    }

    function write($command, $terminate = false)
    {
        if ($command) {
            $com = trim($command);
            $this->encode_length(strlen($com));
            fwrite($this->socket, $com);

            if ($terminate) {
                fwrite($this->socket, chr(0));
            }
            return true;
        }
        return false;
    }

function read($parse = true)
    {
        $res = array();

        // Langkah 1: Membaca aliran data dari MikroTik
        while (true) {
            $length = $this->decode_length();

            if ($length > 0) {
                $line = fread($this->socket, $length);
                
                if ($line === false || $line === '') {
                    break;
                }
                
                $res[] = $line;
            } elseif ($length === 0) {
                // Langkah 2: Jika menerima byte 0 (pemisah kalimat)
                // Cek apakah di dalam tumpukan data sudah ada kata '!done'
                if (in_array('!done', $res)) {
                    break; // Jika ada !done, baru boleh berhenti
                }
            } else {
                // Error atau koneksi putus terputus di tengah jalan
                break;
            }
        }

        // Langkah 3: Memproses baris data mentah menjadi Array PHP
        if ($parse) {
            $parsed = array();
            $currentIndex = -1;

            foreach ($res as $line) {
                if ($line === '!re' || $line === '!trap' || $line === '!done') {
                    $parsed[] = array('type' => $line);
                    $currentIndex = count($parsed) - 1;
                } elseif (substr($line, 0, 1) === '=') {
                    $pos = strpos($line, '=', 1);
                    if ($pos !== false) {
                        $key = substr($line, 1, $pos - 1);
                        $val = substr($line, $pos + 1);
                        if ($currentIndex >= 0) {
                            $parsed[$currentIndex][$key] = $val;
                        }
                    } else {
                        if ($currentIndex >= 0) {
                            $parsed[$currentIndex][substr($line, 1)] = '';
                        }
                    }
                }
            }

            // Langkah 4: Kembalikan data murni atau pesan error
            $result = array();
            foreach ($parsed as $p) {
                if ($p['type'] === '!re') {
                    unset($p['type']);
                    $result[] = $p;
                } elseif ($p['type'] === '!done') {
                    if (count($p) > 1) { // Jika ada data selain 'type' => '!done'
                        unset($p['type']);
                        $result[] = $p;
                    }
                } elseif ($p['type'] === '!trap') {
                    // JIKA ADA ERROR (TRAP), KEMBALIKAN PESAN ERRORNYA
                    return $p; 
                }
            }
            return $result;
        }

        return $res;
    }

    function encode_length($length)
    {
        if ($length < 0x80) {
            fwrite($this->socket, chr($length));
        } elseif ($length < 0x4000) {
            fwrite($this->socket, chr(($length >> 8) | 0x80) . chr($length & 0xff));
        } elseif ($length < 0x200000) {
            fwrite($this->socket, chr(($length >> 16) | 0xc0) . chr(($length >> 8) & 0xff) . chr($length & 0xff));
        } elseif ($length < 0x10000000) {
            fwrite($this->socket, chr(($length >> 24) | 0xe0) . chr(($length >> 16) & 0xff) . chr(($length >> 8) & 0xff) . chr($length & 0xff));
        } else {
            fwrite($this->socket, chr(0xf0) . chr(($length >> 24) & 0xff) . chr(($length >> 16) & 0xff) . chr(($length >> 8) & 0xff) . chr($length & 0xff));
        }
    }

    function decode_length()
    {
        $byte = @fread($this->socket, 1);
        if ($byte === false || $byte === '') return 0;

        $byte = ord($byte);

        if (($byte & 0x80) == 0x00) {
            return $byte;
        } elseif (($byte & 0xc0) == 0x80) {
            $next = @fread($this->socket, 1);
            if ($next === false) return 0;
            return (($byte & 0x3f) << 8) + ord($next);
        } elseif (($byte & 0xe0) == 0xc0) {
            $next = @fread($this->socket, 2);
            if ($next === false || strlen($next) < 2) return 0;
            return (($byte & 0x1f) << 16) + (ord($next[0]) << 8) + ord($next[1]);
        } elseif (($byte & 0xf0) == 0xe0) {
            $next = @fread($this->socket, 3);
            if ($next === false || strlen($next) < 3) return 0;
            return (($byte & 0x0f) << 24) + (ord($next[0]) << 16) + (ord($next[1]) << 8) + ord($next[2]);
        } elseif (($byte & 0xf8) == 0xf0) {
            $next = @fread($this->socket, 4);
            if ($next === false || strlen($next) < 4) return 0;
            return (ord($next[0]) << 24) + (ord($next[1]) << 16) + (ord($next[2]) << 8) + ord($next[3]);
        }
        return 0;
    }
}