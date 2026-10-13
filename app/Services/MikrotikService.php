<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class MikrotikService
{
    const CONNECT_TIMEOUT = 8;
    const READ_TIMEOUT = 8;

    protected $client;
    protected $config;

    /**
     * When true, disconnect() is a no-op so one connection is reused
     * across multiple method calls. Call endBatch() to really disconnect.
     */
    protected bool $batchMode = false;

    public function __construct()
    {
        $this->config = [
            'host'          => env('MIKROTIK_HOST', '101.255.208.150'),
            'fallback_host' => env('MIKROTIK_FALLBACK_HOST'),
            'user'          => env('MIKROTIK_USERNAME', 'admin'),
            'pass'          => env('MIKROTIK_PASSWORD', 'karambia1686'),
            'port'          => (int) env('MIKROTIK_PORT', 8728),
            'timeout'       => self::CONNECT_TIMEOUT,
        ];
    }

    public function connect()
    {
        if (!$this->client) {
            $hosts = array_values(array_filter([
                $this->config['host'],
                $this->config['fallback_host'],
            ]));

            foreach ($hosts as $host) {
                // Try up to 2 times for transient connection hiccups
                for ($attempt = 1; $attempt <= 2; $attempt++) {
                    $client = new RouterosAPI();
                    $client->timeout = $this->config['timeout'];
                    if ($client->connect($host, $this->config['user'], $this->config['pass'], $this->config['port'])) {
                        $this->client = $client;
                        return true;
                    }
                    if ($attempt < 2) {
                        usleep(500000); // 500ms
                    }
                }
            }

            Log::error("Gagal terhubung ke MikroTik: " . implode(', ', $hosts));
            $this->client = null;
            return false;
        }
        return true;
    }

    /**
     * Quick connectivity check without executing commands.
     * Uses raw socket with 3-second timeout.
     */
    public function isOnline(): bool
    {
        $start = microtime(true);
        $socket = @fsockopen($this->config['host'], $this->config['port'], $errno, $errstr, 3);
        if ($socket) {
            $latency = round((microtime(true) - $start) * 1000);
            fclose($socket);
            return true;
        }
        Log::warning('MikroTik unreachable', ['ip' => $this->config['host'], 'error' => $errstr]);
        return false;
    }

    /**
     * Get router status with latency info.
     */
    public function getStatus(): array
    {
        $start = microtime(true);
        $socket = @fsockopen($this->config['host'], $this->config['port'], $errno, $errstr, 3);
        if ($socket) {
            $latency = round((microtime(true) - $start) * 1000);
            fclose($socket);
            return [
                'online' => true,
                'latency_ms' => $latency,
                'checked_at' => now()->toDateTimeString(),
            ];
        }
        Log::warning('MikroTik unreachable', ['ip' => $this->config['host'], 'error' => $errstr]);
        return [
            'online' => false,
            'latency_ms' => null,
            'checked_at' => now()->toDateTimeString(),
            'error' => 'Router tidak dapat dijangkau',
        ];
    }

    public function disconnect()
    {
        // In batch mode, skip disconnect so the connection is reused
        if ($this->batchMode) return;

        $this->forceDisconnect();
    }

    /**
     * Always disconnect, regardless of batch mode.
     */
    protected function forceDisconnect()
    {
        if ($this->client) {
            try {
                $this->client->disconnect();
            } catch (\Throwable $e) {
                // Ignore disconnect errors
            }
            $this->client = null;
        }
    }

    /**
     * Begin batch mode: all methods will reuse a single API connection.
     * Call endBatch() when done to release the connection.
     */
    public function beginBatch(): bool
    {
        if (!$this->connect()) {
            return false;
        }
        $this->batchMode = true;
        return true;
    }

    /**
     * End batch mode and close the shared connection.
     */
    public function endBatch(): void
    {
        $this->batchMode = false;
        $this->forceDisconnect();
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
        $searchName = strtolower($username);
        Log::info("Mikrotik: Mengubah status user {$username} ke " . ($enabled ? "ENABLED" : "DISABLED"));

        $targetUser = null;

        // 1. Coba cari dengan query filter ke MikroTik
        $users = $this->client->comm('/ip/hotspot/user/print', [
            '?name' => $username
        ]);

        if (is_array($users) && !isset($users['message'])) {
            foreach ($users as $u) {
                if (strtolower(trim($u['name'] ?? '')) === $searchName) {
                    $targetUser = $u;
                    break;
                }
            }
        }

        // 2. Jika tidak ketemu dari query langsung, cari secara menyeluruh di semua user (case-insensitive & trimmed)
        if (!$targetUser) {
            $allUsers = $this->client->comm('/ip/hotspot/user/print');
            
            if (isset($allUsers['message'])) {
                Log::error("Mikrotik API Error (Pencarian Total): " . $allUsers['message']);
                $this->disconnect();
                return false;
            }

            if (is_array($allUsers)) {
                foreach ($allUsers as $u) {
                    if (strtolower(trim($u['name'] ?? '')) === $searchName) {
                        $targetUser = $u;
                        break;
                    }
                }
            }
        }

        // Validasi final: JANGAN pernah ambil user sembarangan jika tidak ada yang cocok!
        if (!$targetUser) {
            Log::warning("Mikrotik: User '{$username}' tidak ditemukan di router saat ingin di-" . ($enabled ? "enable" : "disable"));
            $this->disconnect();
            return false;
        }

        $id = $targetUser['.id'] ?? $targetUser['id'] ?? null;
        if (!$id) {
            Log::error("Mikrotik: ID user {$username} tidak valid atau tidak ditemukan dalam struktur data API.", ['data' => $targetUser]);
            $this->disconnect();
            return false;
        }

        // 3. Eksekusi perubahan status pada user yang tepat
        // Menggunakan /disable & /enable bawaan RouterOS + /set untuk kompatibilitas penuh (v6 & v7)
        if ($enabled) {
            $this->client->comm('/ip/hotspot/user/enable', [
                '.id' => $id
            ]);
            $response = $this->client->comm('/ip/hotspot/user/set', [
                '.id' => $id,
                'disabled' => 'no'
            ]);
        } else {
            $this->client->comm('/ip/hotspot/user/disable', [
                '.id' => $id
            ]);
            $response = $this->client->comm('/ip/hotspot/user/set', [
                '.id' => $id,
                'disabled' => 'yes'
            ]);
        }

        // Cek apakah perintah pengubahan status ditolak router
        if (isset($response['type']) && $response['type'] === '!trap') {
            Log::error("Mikrotik API Error (Set Status Gagal): " . ($response['message'] ?? 'Unknown Error'));
            $this->disconnect();
            return false;
        }

        Log::info("Mikrotik: Proses set status untuk {$username} (ID: {$id}) berhasil.");

        // Putus koneksi sebelum fungsi lain jika bukan batch mode
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
        
        $username = trim($username);
        $searchName = strtolower($username);

        // 1. Coba cari dengan ?user (properti aktif di hotspot adalah 'user', bukan 'name')
        $active = $this->client->comm('/ip/hotspot/active/print', [
            '?user' => $username
        ]);

        $targets = [];
        if (is_array($active) && !isset($active['message'])) {
            foreach ($active as $a) {
                if (strtolower(trim($a['user'] ?? '')) === $searchName) {
                    $targets[] = $a;
                }
            }
        }

        // 2. Jika tidak ketemu, cari manual di semua sesi aktif
        if (empty($targets)) {
            $allActive = $this->client->comm('/ip/hotspot/active/print');
            if (is_array($allActive) && !isset($allActive['message'])) {
                foreach ($allActive as $a) {
                    if (strtolower(trim($a['user'] ?? '')) === $searchName) {
                        $targets[] = $a;
                    }
                }
            }
        }

        // HANYA hapus sesi yang benar-benar milik user ini
        foreach ($targets as $a) {
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
        
        $params = [
            'name'     => $data['username'],
            'password' => $data['password'] ?? '',
            'profile'  => $data['profile'] ?? 'default',
            'comment'  => $data['comment'] ?? 'Created by ND Hotspot'
        ];

        if (!empty($data['limit_uptime'])) {
            $params['limit-uptime'] = $data['limit_uptime'];
        }

        $result = $this->client->comm('/ip/hotspot/user/add', $params);
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
        
        $username = trim($username);
        $searchName = strtolower($username);

        $cookies = $this->client->comm('/ip/hotspot/cookie/print', [
            '?user' => $username
        ]);

        $targets = [];
        if (is_array($cookies) && !isset($cookies['message'])) {
            foreach ($cookies as $c) {
                if (strtolower(trim($c['user'] ?? '')) === $searchName) {
                    $targets[] = $c;
                }
            }
        }

        if (empty($targets)) {
            $allCookies = $this->client->comm('/ip/hotspot/cookie/print');
            if (is_array($allCookies) && !isset($allCookies['message'])) {
                foreach ($allCookies as $c) {
                    if (strtolower(trim($c['user'] ?? '')) === $searchName) {
                        $targets[] = $c;
                    }
                }
            }
        }

        // HANYA hapus cookie yang benar-benar milik user ini
        foreach ($targets as $c) {
            if (isset($c['.id'])) {
                $this->client->comm('/ip/hotspot/cookie/remove', [
                    '.id' => $c['.id']
                ]);
            }
        }
        $this->disconnect();
        return true;
    }

    /**
     * Remove a hotspot user AND clear sessions + cookies in ONE connection.
     * Replaces the pattern: removeHotspotUser() + clearUserActiveSessions() + clearUserCookies()
     * which previously opened 3 separate API connections per voucher.
     */
    public function removeAndCleanUser(string $username): bool
    {
        if (!$this->connect()) return false;

        $username = trim($username);

        // 1. Remove hotspot user
        $users = $this->client->comm('/ip/hotspot/user/print', ['?name' => $username]);
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
        foreach ($users as $u) {
            if (isset($u['.id'])) {
                $this->client->comm('/ip/hotspot/user/remove', ['.id' => $u['.id']]);
            }
        }

        // 2. Kick active sessions
        $active = $this->client->comm('/ip/hotspot/active/print', ['?user' => $username]);
        if (empty($active) || !is_array($active)) {
            $allActive = $this->client->comm('/ip/hotspot/active/print');
            $searchName = strtolower(trim($username));
            $active = [];
            if (is_array($allActive)) {
                foreach ($allActive as $a) {
                    if (strtolower(trim($a['user'] ?? '')) === $searchName) {
                        $active[] = $a;
                    }
                }
            }
        }
        foreach ($active as $a) {
            if (isset($a['.id'])) {
                $this->client->comm('/ip/hotspot/active/remove', ['.id' => $a['.id']]);
            }
        }

        // 3. Clear cookies
        $cookies = $this->client->comm('/ip/hotspot/cookie/print', ['?user' => $username]);
        if (is_array($cookies)) {
            foreach ($cookies as $c) {
                if (isset($c['.id'])) {
                    $this->client->comm('/ip/hotspot/cookie/remove', ['.id' => $c['.id']]);
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

    public function getUserStatus($username)
    {
        if (!$this->connect()) {
            return ['exists' => false, 'enabled' => false];
        }

        $searchName = strtolower(trim($username));
        $users = $this->client->comm('/ip/hotspot/user/print', [
            '?name' => $username
        ]);

        $target = null;
        if (is_array($users) && !isset($users['message'])) {
            foreach ($users as $u) {
                if (strtolower(trim($u['name'] ?? '')) === $searchName) {
                    $target = $u;
                    break;
                }
            }
        }

        if (!$target) {
            $allUsers = $this->client->comm('/ip/hotspot/user/print');
            if (is_array($allUsers) && !isset($allUsers['message'])) {
                foreach ($allUsers as $u) {
                    if (strtolower(trim($u['name'] ?? '')) === $searchName) {
                        $target = $u;
                        break;
                    }
                }
            }
        }

        $this->disconnect();

        if (!$target) {
            return ['exists' => false, 'enabled' => false];
        }

        $isDisabled = ($target['disabled'] === 'true' || $target['disabled'] === 'yes');
        return [
            'exists' => true,
            'enabled' => !$isDisabled,
            'user' => $target
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
            if (is_int($key)) {
                $word = (string)$value;
                if ($word !== '' && $word[0] !== '=' && $word[0] !== '?' && $word[0] !== '~') {
                    $word = '=' . $word;
                }
                $this->write($word, false);
            } else {
                $firstChar = $key[0] ?? '';
                if ($firstChar === '?' || $firstChar === '~') {
                    // Query word (?name=foo) or API attribute (~proplist=...)
                    $this->write($key . '=' . $value, false);
                } elseif ($firstChar === '=') {
                    $this->write($key . '=' . $value, false);
                } else {
                    $this->write('=' . $key . '=' . $value, false);
                }
            }
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