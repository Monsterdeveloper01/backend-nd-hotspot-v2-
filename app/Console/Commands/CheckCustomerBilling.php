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
        \Log::info("Billing Check started at: " . now());
        $today = Carbon::today();
        $tomorrow = Carbon::tomorrow();
        
        $this->info("Checking billing for Today: {$today->toDateString()} and Tomorrow: {$tomorrow->toDateString()}");

        // 0. Reset Status to 'unpaid' if today is the due_date or passed and they are currently 'paid'
        $toReset = Customer::whereDate('due_date', '<=', $today)
            ->where('status_bayar', 'paid')
            ->get();
            
        \Log::info("Found " . $toReset->count() . " customers to reset to unpaid.");
        foreach ($toReset as $c) {
            $c->update(['status_bayar' => 'unpaid']);
            $this->info("Reset status for customer: {$c->name} to unpaid.");
        }

        // 1. WhatsApp Reminders (H-1)
        $h1Customers = Customer::whereDate('due_date', $tomorrow)->get();
        \Log::info("Found " . $h1Customers->count() . " customers for H-1 reminder.");
        foreach ($h1Customers as $customer) {
            if ($customer->status_bayar === 'unpaid') {
                $msg = "Hallo *{$customer->name}*, pelanggan ND-Hotspot 👋\n\n" .
                       "Terima kasih telah setia menggunakan layanan internet kami.\n" .
                       "Kami ingin mengingatkan bahwa tagihan bulanan Anda akan jatuh tempo *BESOK* (" . $customer->due_date->format('d/m/Y') . ") dengan total: *Rp " . number_format($customer->billing_amount, 0, ',', '.') . "*\n\n" .
                       "Mohon segera lakukan pembayaran agar layanan internet tetap lancar dan tidak terisolir otomatis.\n\n" .
                       "*CARA PEMBAYARAN:*\n" .
                       "Silakan akses link di bawah ini:\n" .
                       "👉 " . env('FRONTEND_URL', 'https://nd-hotpot.net') . "/payment\n" .
                       "-> Masukan username\n" .
                       "–> Otomatis keluar nama pelanggan\n" .
                       "–> Pilih bayar \n" .
                       "–> Bayar sekarang \n" .
                       "–> Pilih metode pembayaran (Gopay, Virtual Account atau QRIS)\n" .
                       "Setelah pembayaran selesai otomatis system mengirim bukti pembayaran ke WA pelanggan\n\n" .
                       "Abaikan pesan ini jika Anda sudah melakukan pembayaran.\n\n" .
                       "Pesan ini dikirim otomatis oleh sistem.\n" .
                       "Jika anda mengalami kesulitan silahkan hubungi\n" .
                       "Admin: 0812-9588-587\n\n" .
                       "Hormat kami,\n" .
                       "*ND-Hotspot* 💡";
                $this->whatsapp->sendMessage($customer->whatsapp, $msg);
            }
        }

        // 1. WhatsApp Reminders (H)
        $dueToday = Customer::whereDate('due_date', $today)->where('status_bayar', 'unpaid')->get();
        \Log::info("Found " . $dueToday->count() . " customers for Today reminder.");
        foreach ($dueToday as $customer) {
            $msg = "Hallo *{$customer->name}*, pelanggan ND-Hotspot 👋\n\n" .
                   "Terima kasih telah setia menggunakan layanan internet kami.\n" .
                   "Kami ingin mengingatkan bahwa tagihan bulanan Anda *JATUH TEMPO HARI INI* dengan total: *Rp " . number_format($customer->billing_amount, 0, ',', '.') . "*\n\n" .
                   "Mohon segera lakukan pembayaran agar layanan internet tetap lancar dan tidak terisolir otomatis.\n\n" .
                   "*CARA PEMBAYARAN:*\n" .
                   "Silakan akses link di bawah ini:\n" .
                   "👉 " . env('FRONTEND_URL', 'https://nd-hotpot.net') . "/payment\n" .
                   "-> Masukan username\n" .
                   "–> Otomatis keluar nama pelanggan\n" .
                   "–> Pilih bayar \n" .
                   "–> Bayar sekarang \n" .
                   "–> Pilih metode pembayaran (Gopay, Virtual Account atau QRIS)\n" .
                   "Setelah pembayaran selesai otomatis system mengirim bukti pembayaran ke WA pelanggan\n\n" .
                   "Abaikan pesan ini jika Anda sudah melakukan pembayaran.\n\n" .
                   "Pesan ini dikirim otomatis oleh sistem.\n" .
                   "Jika anda mengalami kesulitan silahkan hubungi\n" .
                   "Admin: 0812-9588-587\n\n" .
                   "Hormat kami,\n" .
                   "*ND-Hotspot* 💡";
            $this->whatsapp->sendMessage($customer->whatsapp, $msg);
        }

        // 2. Isolate on H+2 (If still unpaid)
        $isolateDate = Carbon::today()->subDays(2);
        $toIsolate = Customer::whereDate('due_date', '<=', $isolateDate)
            ->where('status_bayar', 'unpaid')
            ->where('is_isolated', false)
            ->get();
        \Log::info("Found " . $toIsolate->count() . " customers to Isolate.");

        foreach ($toIsolate as $customer) {
            $this->mikrotik->setUserStatus($customer->name, false);
            $this->mikrotik->clearUserActiveSessions($customer->name);
            $this->mikrotik->clearUserCookies($customer->name);

            $customer->is_isolated = true;
            $customer->save();

            $msg = "🚫 *LAYANAN TERISOLIR*\n\n" .
                   "Hallo *{$customer->name}*,\n" .
                   "Mohon maaf, layanan internet Anda telah *DINONAKTIFKAN SEMENTARA* karena keterlambatan pembayaran tagihan sebesar *Rp " . number_format($customer->billing_amount, 0, ',', '.') . "*\n\n" .
                   "Silakan segera lakukan pembayaran untuk mengaktifkan kembali layanan Anda secara otomatis.\n\n" .
                   "*CARA PEMBAYARAN:*\n" .
                   "👉 " . env('FRONTEND_URL', 'https://nd-hotpot.net') . "/payment\n\n" .
                   "Hubungi Admin: 0812-9588-587\n\n" .
                   "Hormat kami,\n" .
                   "*ND-Hotspot* 💡";
            $this->whatsapp->sendMessage($customer->whatsapp, $msg);
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
