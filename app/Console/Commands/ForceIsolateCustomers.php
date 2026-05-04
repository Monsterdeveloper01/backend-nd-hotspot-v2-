<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Services\MikrotikService;
use App\Services\WhatsAppService;
use Carbon\Carbon;

class ForceIsolateCustomers extends Command
{
    protected $signature = 'customer:force-isolate {--notify : Send WhatsApp notification to isolated customers}';
    protected $description = 'Forcefully isolate all customers whose due date has passed and are still unpaid';

    protected $mikrotik;
    protected $whatsapp;

    public function __construct(MikrotikService $mikrotik, WhatsAppService $whatsapp)
    {
        parent::__construct();
        $this->mikrotik = $mikrotik;
        $this->whatsapp = $whatsapp;
    }

    public function handle()
    {
        $today = Carbon::now();
        
        $toIsolate = Customer::where('due_date', '<', $today)
            ->where('status_bayar', '!=', 'paid')
            ->where('is_isolated', false)
            ->get();

        if ($toIsolate->isEmpty()) {
            $this->info('No overdue customers found to isolate.');
            return;
        }

        $this->info("Found {$toIsolate->count()} customers to isolate.");

        foreach ($toIsolate as $customer) {
            $this->info("Isolating: {$customer->name} (Due: {$customer->due_date->format('d/m/Y')})");
            
            try {
                // 1. Mikrotik Isolation
                $this->mikrotik->setUserStatus($customer->name, false);
                $this->mikrotik->clearUserActiveSessions($customer->name);
                $this->mikrotik->clearUserCookies($customer->name);

                // 2. DB Status Update
                $customer->is_isolated = true;
                $customer->save();

                // 3. Optional Notification
                if ($this->option('notify')) {
                    $msg = "🚫 *LAYANAN TERISOLIR OTOMATIS*\n\n" .
                           "Halo *{$customer->name}*,\n" .
                           "Layanan internet Anda telah *DINONAKTIFKAN* karena telah melewati batas jatuh tempo (" . $customer->due_date->format('d/m/Y') . ").\n\n" .
                           "💰 *Total Tagihan:* Rp " . number_format($customer->billing_amount, 0, ',', '.') . "\n\n" .
                           "Segera lakukan pembayaran untuk mengaktifkan kembali layanan Anda:\n" .
                           "👉 " . env('FRONTEND_URL', 'https://nd-hotpot.net') . "/payment\n\n" .
                           "Hormat kami,\n" .
                           "*ND-Hotspot* 💡";
                    
                    $this->whatsapp->sendMessage($customer->whatsapp, $msg);
                }
                
                $this->info("Success: {$customer->name} isolated.");
            } catch (\Exception $e) {
                $this->error("Error isolating {$customer->name}: " . $e->getMessage());
            }
        }

        $this->info('Force isolation process completed.');
    }
}
