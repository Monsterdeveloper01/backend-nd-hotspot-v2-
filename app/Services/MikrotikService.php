<?php

namespace App\Services;

use RouterOS\Client;
use RouterOS\Query;
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
            'timeout' => 60, // Sesuai V1 yang stabil
        ];
    }

    /**
     * Membuka koneksi ke Mikrotik
     */
    public function connect()
    {
        try {
            $this->client = new Client($this->config);
            return true;
        } catch (\Exception $e) {
            Log::error("Mikrotik Connect Error: " . $e->getMessage());
            $this->client = null;
            return false;
        }
    }

    /**
     * Ambil Semua User Hotspot (Bulk)
     */
    public function getAllHotspotUsers()
    {
        if (!$this->client && !$this->connect()) return [];

        try {
            $query = new Query('/ip/hotspot/user/print');
            return $this->client->query($query)->read();
        } catch (\Exception $e) {
            Log::error("Mikrotik GetAllUsers Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Ambil User yang Sedang Online
     */
    public function getActiveUsers()
    {
        if (!$this->client && !$this->connect()) return [];

        try {
            $query = new Query('/ip/hotspot/active/print');
            return $this->client->query($query)->read();
        } catch (\Exception $e) {
            Log::error("Mikrotik GetActiveUsers Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Set Status User (Enable/Disable)
     */
    public function setUserStatus($username, $enabled)
    {
        if (!$this->client && !$this->connect()) return false;

        try {
            // Cari ID user berdasarkan nama
            $query = (new Query('/ip/hotspot/user/print'))
                ->where('name', $username);
            $user = $this->client->query($query)->read();

            if (empty($user)) return false;

            $id = $user[0]['.id'];

            // Update status
            $update = (new Query('/ip/hotspot/user/set'))
                ->equal('.id', $id)
                ->equal('disabled', $enabled ? 'no' : 'yes');
            $this->client->query($update)->read();

            // Jika didisable, tendang dari active
            if (!$enabled) {
                $this->kickUser($username);
            }

            return true;
        } catch (\Exception $e) {
            Log::error("Mikrotik SetStatus Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Tendang user dari daftar aktif
     */
    public function kickUser($username)
    {
        if (!$this->client && !$this->connect()) return false;

        try {
            $query = (new Query('/ip/hotspot/active/print'))
                ->where('name', $username);
            $active = $this->client->query($query)->read();

            foreach ($active as $a) {
                $remove = (new Query('/ip/hotspot/active/remove'))
                    ->equal('.id', $a['.id']);
                $this->client->query($remove)->read();
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Buat User Baru
     */
    public function createUser($data)
    {
        if (!$this->client && !$this->connect()) return false;

        try {
            $query = (new Query('/ip/hotspot/user/add'))
                ->equal('name', $data['username'])
                ->equal('password', $data['password'] ?? '')
                ->equal('profile', $data['profile'] ?? 'default')
                ->equal('comment', $data['comment'] ?? 'Created by ND Hotspot');
            
            return $this->client->query($query)->read();
        } catch (\Exception $e) {
            Log::error("Mikrotik CreateUser Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update Profile User
     */
    public function updateUserProfile($username, $profile)
    {
        if (!$this->client && !$this->connect()) return false;

        try {
            $query = (new Query('/ip/hotspot/user/print'))
                ->where('name', $username);
            $user = $this->client->query($query)->read();

            if (empty($user)) return false;

            $update = (new Query('/ip/hotspot/user/set'))
                ->equal('.id', $user[0]['.id'])
                ->equal('profile', $profile);
            
            $this->client->query($update)->read();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Hapus User
     */
    public function deleteUser($username)
    {
        if (!$this->client && !$this->connect()) return false;

        try {
            $query = (new Query('/ip/hotspot/user/print'))
                ->where('name', $username);
            $user = $this->client->query($query)->read();

            if (empty($user)) return false;

            $remove = (new Query('/ip/hotspot/user/remove'))
                ->equal('.id', $user[0]['.id']);
            
            $this->client->query($remove)->read();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
