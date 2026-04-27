<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Services\MikrotikService;
use App\Services\WhatsAppService;
use Carbon\Carbon;

class CheckCustomerBilling extends Command
{
    protected $signature = 'billing:check';
    protected $description = 'Check customer due dates and handle notifications/isolation';

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
        $today = Carbon::today();
        
        // 0. Pre-Due Reminders (H-1)
        $reminders = [
            ['days' => 1, 'msg' => "Halo %name%, tagihan internet Anda sebesar %amount% akan jatuh tempo BESOK (%date%). Mohon segera melakukan pembayaran agar layanan tidak terputus."]
        ];

        foreach ($reminders as $rem) {
            $date = Carbon::today()->addDays($rem['days']);
            $customers = Customer::whereDate('due_date', $date)->where('status_bayar', 'unpaid')->get();
            
            foreach ($customers as $customer) {
                $msg = str_replace(
                    ['%name%', '%amount%', '%date%'], 
                    [$customer->name, "Rp " . number_format($customer->billing_amount, 0, ',', '.'), $customer->due_date->format('d M Y')], 
                    $rem['msg']
                );
                $this->whatsapp->sendMessage($customer->whatsapp, $msg);
            }
        }

        // 1. Send Notifications on Due Date (H)
        $dueToday = Customer::whereDate('due_date', $today)
            ->where('status_bayar', 'unpaid')
            ->get();

        foreach ($dueToday as $customer) {
            $this->whatsapp->sendMessage($customer->whatsapp, "Halo {$customer->name}, tagihan internet Anda sebesar Rp " . number_format($customer->billing_amount, 0, ',', '.') . " jatuh tempo hari ini. Mohon segera melakukan pembayaran agar layanan tetap aktif.");
            $this->info("Notified {$customer->name}");
        }

        // 2. Isolate on H+2 (If still unpaid)
        $isolateDate = Carbon::today()->subDays(2);
        $toIsolate = Customer::whereDate('due_date', $isolateDate)
            ->where('status_bayar', 'unpaid')
            ->where('is_isolated', false)
            ->get();

        foreach ($toIsolate as $customer) {
            // Disable in Mikrotik
            $this->mikrotik->setUserStatus($customer->name, false);
            // Clear active sessions & cookies
            $this->mikrotik->clearUserActiveSessions($customer->name);
            $this->mikrotik->clearUserCookies($customer->name);

            $customer->is_isolated = true;
            $customer->save();

            $this->whatsapp->sendMessage($customer->whatsapp, "Halo {$customer->name}, layanan internet Anda telah dinonaktifkan sementara karena keterlambatan pembayaran 2 hari. Silakan lunasi tagihan Anda untuk mengaktifkan kembali layanan.");
            $this->warn("Isolated {$customer->name}");
        }
    }
}
