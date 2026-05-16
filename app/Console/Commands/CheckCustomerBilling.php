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
        \Log::info("=== Billing Check Started ===");
        $today = Carbon::today();
        $tomorrow = Carbon::tomorrow();
        $yesterday = Carbon::yesterday();
        
        $this->info("Checking billing for Today: {$today->toDateString()}");

        // 0. Reset Status to 'unpaid' if today is the due_date or passed and they are currently 'paid'
        $toReset = Customer::whereDate('due_date', '<=', $today)
            ->where('status_bayar', 'paid')
            ->get();
            
        foreach ($toReset as $c) {
            $c->update(['status_bayar' => 'unpaid']);
            \Log::info("User Reset: {$c->name} marked as unpaid (due date reached/passed).");
        }

        // 1. WhatsApp Reminders (H-1)
        $h1Customers = Customer::whereDate('due_date', $tomorrow)->get();
        foreach ($h1Customers as $customer) {
            if ($customer->status_bayar === 'unpaid') {
                $this->sendWhatsAppReminder($customer, "H-1 (Besok)");
            } else {
                \Log::info("WA Skip: {$customer->name} (H-1) is already PAID.");
            }
        }

        // 2. WhatsApp Reminders (H - Hari Ini)
        $dueToday = Customer::whereDate('due_date', $today)->get();
        foreach ($dueToday as $customer) {
            if ($customer->status_bayar === 'unpaid') {
                $this->sendWhatsAppReminder($customer, "H (Hari Ini)");
            } else {
                \Log::info("WA Skip: {$customer->name} (Today) is already PAID.");
            }
        }

        // 3. WhatsApp Reminders (H+1 - Lewat Jatuh Tempo)
        $hPlus1 = Customer::whereDate('due_date', $yesterday)->get();
        foreach ($hPlus1 as $customer) {
            if ($customer->status_bayar === 'unpaid') {
                $this->sendWhatsAppReminder($customer, "H+1 (Terlambat)");
            } else {
                \Log::info("WA Skip: {$customer->name} (H+1) is already PAID.");
            }
        }

        // 4. Isolate on H+2 (If still unpaid)
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

            \Log::info("ISOLASI: {$customer->name} berhasil diisolir.");
            $this->sendWhatsAppIsolation($customer);
        }

        // 5. Telegram Report
        $this->sendTelegramSummary($tomorrow);
        
        \Log::info("=== Billing Check Completed ===");
        $this->info('Billing check and Telegram summary completed.');
    }

    private function sendWhatsAppReminder($customer, $label)
    {
        $statusLabel = ($label == "H (Hari Ini)") ? "*JATUH TEMPO HARI INI*" : (($label == "H+1 (Terlambat)") ? "*SUDAH MELEWATI JATUH TEMPO*" : "*BESOK* (" . $customer->due_date->format('d/m/Y') . ")");
        
        $msg = "Hallo *{$customer->name}*, pelanggan ND-Hotspot 👋\n\n" .
               "Terima kasih telah setia menggunakan layanan internet kami.\n" .
               "Kami ingin mengingatkan bahwa tagihan bulanan Anda {$statusLabel} dengan total: *Rp " . number_format($customer->billing_amount, 0, ',', '.') . "*\n\n" .
               "Mohon segera lakukan pembayaran agar layanan internet tetap lancar dan tidak terisolir otomatis.\n\n" .
               "*CARA PEMBAYARAN:*\n" .
               "Silakan akses link di bawah ini:\n" .
               "👉 " . env('FRONTEND_URL', 'https://nd-hotpot.net') . "/payment\n\n" .
               "Abaikan pesan ini jika Anda sudah melakukan pembayaran.\n" .
               "Pesan ini dikirim otomatis oleh sistem.\n\n" .
               "Hormat kami,\n" .
               "*ND-Hotspot* 💡";

        $res = $this->whatsapp->sendMessage($customer->whatsapp, $msg);
        if ($res) {
            \Log::info("WA Sent: {$customer->name} ({$label}) success.");
        } else {
            \Log::error("WA Failed: {$customer->name} ({$label}) gateway error.");
        }
    }

    private function sendWhatsAppIsolation($customer)
    {
        $msg = "🚫 *LAYANAN TERISOLIR*\n\n" .
               "Hallo *{$customer->name}*,\n" .
               "Mohon maaf, layanan internet Anda telah *DINONAKTIFKAN SEMENTARA* karena keterlambatan pembayaran tagihan sebesar *Rp " . number_format($customer->billing_amount, 0, ',', '.') . "*\n\n" .
               "Silakan segera lakukan pembayaran untuk mengaktifkan kembali layanan Anda secara otomatis.\n\n" .
               "*CARA PEMBAYARAN:*\n" .
               "👉 " . env('FRONTEND_URL', 'https://nd-hotpot.net') . "/payment\n\n" .
               "Hormat kami,\n" .
               "*ND-Hotspot* 💡";

        $this->whatsapp->sendMessage($customer->whatsapp, $msg);
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
