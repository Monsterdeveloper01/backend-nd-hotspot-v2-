<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Services\MikrotikService;

class SyncIsolationStatus extends Command
{
    protected $signature = 'customer:sync-isolation {--force : Force disable all isolated customers in MikroTik}';
    protected $description = 'Sync isolation status between database and MikroTik router';

    protected $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        parent::__construct();
        $this->mikrotik = $mikrotik;
    }

    public function handle()
    {
        $this->info("Menghubungkan ke MikroTik...");
        
        if (!$this->mikrotik->connect()) {
            $this->error("Gagal terhubung ke MikroTik! Periksa IP dan kredensial di .env");
            return 1;
        }

        $this->info("Koneksi ke MikroTik BERHASIL.");

        // 1. Ambil pelanggan yang di database berstatus TERISOLIR (is_isolated = true)
        $isolatedCustomers = Customer::where('is_isolated', true)->get();
        $this->info("Ditemukan {$isolatedCustomers->count()} pelanggan berstatus TERISOLIR di database.");

        $successCount = 0;
        $failCount = 0;

        foreach ($isolatedCustomers as $c) {
            $this->line("Memeriksa: {$c->name}...");
            $status = $this->mikrotik->getUserStatus($c->name);

            if (!$status['exists']) {
                $this->warn(" -> User '{$c->name}' TIDAK DITEMUKAN di MikroTik.");
                $failCount++;
                continue;
            }

            if ($status['enabled']) {
                $this->warn(" -> User '{$c->name}' MASIH AKTIF di MikroTik. Mendisable sekarang...");
                $disabled = $this->mikrotik->setUserStatus($c->name, false);
                if ($disabled) {
                    $this->info(" -> [SUKSES] User '{$c->name}' BERHASIL di-disable di MikroTik.");
                    $successCount++;
                } else {
                    $this->error(" -> [GAGAL] Gagal mendisable '{$c->name}' di MikroTik.");
                    $failCount++;
                }
            } else {
                $this->info(" -> User '{$c->name}' SUDAH nonaktif (disabled) di MikroTik. OK.");
                $successCount++;
            }
        }

        // 2. Ambil pelanggan yang di database berstatus AKTIF (is_isolated = false) tapi di router nonaktif
        $activeCustomers = Customer::where('is_isolated', false)->get();
        $this->info("\nMemeriksa {$activeCustomers->count()} pelanggan berstatus AKTIF di database...");

        foreach ($activeCustomers as $c) {
            $status = $this->mikrotik->getUserStatus($c->name);
            if ($status['exists'] && !$status['enabled']) {
                $this->warn(" -> User '{$c->name}' di DB aktif tapi di router NONAKTIF. Mengaktifkan...");
                $this->mikrotik->setUserStatus($c->name, true);
                $this->info(" -> [SUKSES] User '{$c->name}' diaktifkan di router.");
            }
        }

        $this->info("\nSinkronisasi selesai! Sukses: {$successCount}, Gagal/Perlu dicek: {$failCount}");
        return 0;
    }
}
