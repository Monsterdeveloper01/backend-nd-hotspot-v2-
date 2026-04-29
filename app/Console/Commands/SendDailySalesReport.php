<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Transaction;
use App\Models\Customer;
use App\Services\TelegramService;
use Carbon\Carbon;

class SendDailySalesReport extends Command
{
    protected $signature = 'app:send-daily-sales-report';
    protected $description = 'Send daily sales summary to Telegram at 07:00 AM';

    public function handle(TelegramService $telegram)
    {
        $yesterday = Carbon::yesterday();
        $today = Carbon::today();

        // 1. Query Voucher Sales
        $vouchers = Transaction::where('status', 'success')
            ->whereBetween('updated_at', [$yesterday->startOfDay(), $yesterday->endOfDay()])
            ->get();
        
        $totalVouchers = $vouchers->sum('amount');
        $countVouchers = $vouchers->count();

        // 2. Query Monthly Bill Payments (Heuristic based on Customer updated_at if no separate transaction log)
        // Ideally we should have a 'bill_payments' table or use 'transactions' table for bills too.
        // For now, let's look for customers who were paid yesterday.
        $bills = Customer::where('status_bayar', 'paid')
            ->whereBetween('updated_at', [$yesterday->startOfDay(), $yesterday->endOfDay()])
            ->get();
            
        $totalBills = $bills->sum('billing_amount');
        $countBills = $bills->count();

        $totalRevenue = $totalVouchers + $totalBills;

        $message = "📊 <b>LAPORAN PENJUALAN HARIAN</b>\n";
        $message .= "📅 Tanggal: <b>" . $yesterday->format('d M Y') . "</b>\n";
        $message .= "━━━━━━━━━━━━━━━━━━\n\n";
        
        $message .= "🛒 <b>Penjualan Voucher:</b>\n";
        $message .= "• Total Transaksi: {$countVouchers}\n";
        $message .= "• Total Rupiah: <b>Rp " . number_format($totalVouchers, 0, ',', '.') . "</b>\n\n";

        $message .= "💰 <b>Pembayaran Bulanan:</b>\n";
        $message .= "• Total Pelanggan: {$countBills}\n";
        $message .= "• Total Rupiah: <b>Rp " . number_format($totalBills, 0, ',', '.') . "</b>\n\n";

        $message .= "━━━━━━━━━━━━━━━━━━\n";
        $message .= "💵 <b>TOTAL PENDAPATAN:</b>\n";
        $message .= "👉 <b>Rp " . number_format($totalRevenue, 0, ',', '.') . "</b>\n\n";
        
        $message .= "<i>Sistem ND-Hotspot siap beroperasi kembali!</i>";

        $telegram->sendMessage($message);
        $this->info('Daily report sent to Telegram.');
    }
}
