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
    protected $description = 'Proses auto-debit ND-Points untuk perpanjangan voucher customer yang telah habis';

    /**
     * Execute the console command.
     */
    public function handle(AutoDebitService $service)
    {
        $this->info('Memulai pengecekan auto-debit ND-Points...');
        $results = $service->runAll();

        $this->info("Pengecekan selesai. Total discan: {$results['total_scanned']}, Sukses: {$results['success']}, Gagal/Dilewati: {$results['failed']}");
        return 0;
    }
}
