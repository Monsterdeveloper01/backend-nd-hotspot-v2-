<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AutoDebitService;

class ProcessPointAutoDebit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'points:autodebit';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Proses ND Auto-Voucher untuk perpanjangan voucher customer';

    /**
     * Execute the console command.
     */
    public function handle(AutoDebitService $service)
    {
        $this->info('Memulai pengecekan ND Auto-Voucher...');
        $results = $service->runAll();

        $this->info("Pengecekan selesai. Total: {$results['total_scanned']}, Dieksekusi: {$results['processed']} (Sukses: {$results['success']}, Gagal: {$results['failed']}), Dilewati: {$results['skipped']}");
        return 0;
    }
}
