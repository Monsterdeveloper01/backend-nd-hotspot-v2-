<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\VoucherSyncService;

class SyncVouchersToMikrotik extends Command
{
    protected $signature = 'voucher:sync-mikrotik 
                            {--dry-run : Simulasi tanpa menyuntikkan ke MikroTik}
                            {--only-rewards : Hanya sinkron voucher dari program loyalty/reward}
                            {--no-stock : Jangan sertakan voucher stock yang belum terjual}
                            {--force : Update limit-uptime jika user sudah ada di MikroTik}';

    protected $description = 'Sinkronkan voucher aktif/belum expired ke MikroTik dengan penyesuaian sisa waktu otomatis';

    protected $syncService;

    public function __construct(VoucherSyncService $syncService)
    {
        parent::__construct();
        $this->syncService = $syncService;
    }

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $onlyRewards = $this->option('only-rewards');
        $noStock = $this->option('no-stock');
        $force = $this->option('force');

        $this->info("======================================================");
        $this->info("   ND-HOTSPOT SMART VOUCHER SYNCHRONIZATION TOOL      ");
        $this->info("======================================================");

        if ($dryRun) {
            $this->warn("MODUS: SIMULASI / DRY-RUN (Tidak ada perubahan di router)");
        } else {
            $this->warn("MODUS: EKSEKUSI NYATA KE MIKROTIK");
        }

        $this->line("Memulai pemindaian voucher di database...");

        $result = $this->syncService->sync([
            'dry_run' => $dryRun,
            'only_rewards' => $onlyRewards,
            'include_stock' => !$noStock,
            'force_update' => $force,
        ]);

        if (!$result['success']) {
            $this->error("GAGAL: " . $result['message']);
            return 1;
        }

        $stats = $result['stats'];

        $this->newLine();
        $this->info("Ringkasan Hasil Sinkronisasi:");
        $this->table(
            ['Metrik', 'Jumlah'],
            [
                ['Total Voucher Dipindai', $stats['total_scanned']],
                ['Sudah Ada di MikroTik', $stats['already_in_mikrotik']],
                ['Disinkron (Belum Pernah Dipakai - Durasi Penuh)', $stats['synced_never_used']],
                ['Disinkron (Sedang Aktif - Sisa Waktu Disesuaikan)', $stats['synced_active_remaining']],
                ['User Diperbarui (--force)', $stats['updated']],
                ['Dilewati (Sudah Expired)', $stats['skipped_expired']],
                ['Gagal Inject', $stats['failed']],
            ]
        );

        if (!empty($result['details'])) {
            $this->newLine();
            $this->info("Rincian 20 Voucher Pertama:");
            $sample = array_slice($result['details'], 0, 20);
            $tableData = [];
            foreach ($sample as $d) {
                $tableData[] = [
                    $d['code'] ?? '-',
                    $d['plan'] ?? '-',
                    $d['source'] ?? 'purchase',
                    $d['uptime'] ?? '-',
                    $d['human_time'] ?? ($d['reason'] ?? '-'),
                    $d['action'] ?? '-',
                ];
            }
            $this->table(['Kode', 'Paket', 'Sumber', 'Limit Uptime', 'Keterangan Waktu', 'Aksi'], $tableData);

            if (count($result['details']) > 20) {
                $this->line("... dan " . (count($result['details']) - 20) . " voucher lainnya.");
            }
        }

        $this->newLine();
        $this->info($result['message']);
        return 0;
    }
}
