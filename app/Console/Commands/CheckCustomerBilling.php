<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Services\MikrotikService;
use App\Services\WhatsAppService;
use App\Services\TelegramService;
use Carbon\Carbon;

class CheckCustomerBilling extends Command
{
    protected $signature = 'billing:check';
    protected $description = 'Check customer due dates and handle notifications/isolation';

    protected $mikrotik;
    protected $whatsapp;
    protected $telegram;

    public function __construct(MikrotikService $mikrotik, WhatsAppService $whatsapp, TelegramService $telegram)
    {
        parent::__construct();
        $this->mikrotik = $mikrotik;
        $this->whatsapp = $whatsapp;
        $this->telegram = $telegram;
    }

    public function handle()
    {
        $today = Carbon::today();
        $tomorrow = Carbon::tomorrow();
        
        // 0. WhatsApp Reminders (H-1)
        $h1Customers = Customer::whereDate('due_date', $tomorrow)->get();
        foreach ($h1Customers as $customer) {
            if ($customer->status_bayar === 'unpaid') {
                $msg = "Halo {$customer->name}, tagihan internet Anda sebesar Rp " . number_format($customer->billing_amount, 0, ',', '.') . " akan jatuh tempo BESOK ({$customer->due_date->format('d M Y')}). Mohon segera melakukan pembayaran agar layanan tidak terputus.";
                $this->whatsapp->sendMessage($customer->whatsapp, $msg);
            }
        }

        // 1. WhatsApp Reminders (H)
        $dueToday = Customer::whereDate('due_date', $today)->where('status_bayar', 'unpaid')->get();
        foreach ($dueToday as $customer) {
            $this->whatsapp->sendMessage($customer->whatsapp, "Halo {$customer->name}, tagihan internet Anda sebesar Rp " . number_format($customer->billing_amount, 0, ',', '.') . " jatuh tempo hari ini. Mohon segera melakukan pembayaran agar layanan tetap aktif.");
        }

        // 2. Isolate on H+2 (If still unpaid)
        $isolateDate = Carbon::today()->subDays(2);
        $toIsolate = Customer::whereDate('due_date', '<=', $isolateDate)
            ->where('status_bayar', 'unpaid')
            ->where('is_isolated', false)
            ->get();

        foreach ($toIsolate as $customer) {
            $this->mikrotik->setUserStatus($customer->name, false);
            $this->mikrotik->clearUserActiveSessions($customer->name);
            $this->mikrotik->clearUserCookies($customer->name);

            $customer->is_isolated = true;
            $customer->save();

            $this->whatsapp->sendMessage($customer->whatsapp, "Halo {$customer->name}, layanan internet Anda telah dinonaktifkan sementara karena keterlambatan pembayaran. Silakan lunasi tagihan Anda untuk mengaktifkan kembali layanan.");
        }

        // 3. Telegram Report (The new requirement)
        $this->sendTelegramSummary($tomorrow);
        
        $this->info('Billing check and Telegram summary completed.');
    }

    private function sendTelegramSummary($tomorrow)
    {
        // A. Tomorrow's Due List
        $h1Customers = Customer::whereDate('due_date', $tomorrow)->get();
        $h1List = "";
        if ($h1Customers->count() > 0) {
            foreach ($h1Customers as $c) {
                $status = $c->status_bayar === 'paid' ? "✅ Sudah Bayar" : "❌ Belum Bayar";
                $h1List .= "• {$c->name} ({$status})\n";
            }
        } else {
            $h1List = "• <i>Tidak ada pelanggan jatuh tempo besok</i>\n";
        }

        // B. Isolated List
        $isolatedCustomers = Customer::where('is_isolated', true)->get();
        $isolatedList = "";
        if ($isolatedCustomers->count() > 0) {
            foreach ($isolatedCustomers as $c) {
                $isolatedList .= "• {$c->name} (Tagihan: Rp " . number_format($c->billing_amount, 0, ',', '.') . ")\n";
            }
        } else {
            $isolatedList = "• <i>Tidak ada pelanggan terisolir</i>\n";
        }

        $message = "📅 <b>LAPORAN JATUH TEMPO & ISOLIR</b>\n";
        $message .= "━━━━━━━━━━━━━━━━━━\n\n";
        $message .= "⏰ <b>Jatuh Tempo Besok (" . $tomorrow->format('d M Y') . "):</b>\n";
        $message .= $h1List . "\n";
        $message .= "🚫 <b>Pelanggan Terisolir Saat Ini:</b>\n";
        $message .= $isolatedList . "\n";
        $message .= "━━━━━━━━━━━━━━━━━━\n";
        $message .= "<i>Pastikan cek status pembayaran di dashboard sebelum tindakan manual.</i>";

        $this->telegram->sendMessage($message);
    }
}
