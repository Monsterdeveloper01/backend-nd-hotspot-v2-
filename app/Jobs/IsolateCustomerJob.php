<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\MikrotikService;
use App\Services\WhatsAppService;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

class IsolateCustomerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $customer;

    /**
     * Retry up to 3 times with exponential backoff (10s, 30s, 60s)
     * so transient MikroTik connection failures get a fair chance.
     */
    public $tries = 3;

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }

    public function handle(MikrotikService $mikrotik, WhatsAppService $whatsapp)
    {
        $customerName = $this->customer->name;

        try {
            Log::info("ISOLASI JOB: Starting isolation for {$customerName}");
            
            // 1. Disable user on MikroTik router
            $disabled = $mikrotik->setUserStatus($customerName, false);
            
            if ($disabled === false) {
                throw new \RuntimeException("MikroTik setUserStatus returned false for {$customerName}");
            }

            // 2. Clear active sessions & cookies
            $mikrotik->clearUserActiveSessions($customerName);
            $mikrotik->clearUserCookies($customerName);

            Log::info("ISOLASI JOB: {$customerName} berhasil di-disable di MikroTik.");

            // 3. Confirm is_isolated in DB (already set to true in billing:check, but confirm it)
            $this->customer->refresh();
            if (!$this->customer->is_isolated) {
                $this->customer->update(['is_isolated' => true]);
            }

            // 4. Send WhatsApp isolation notification ONLY after MikroTik success
            try {
                $msg = "🚫 *LAYANAN TERISOLIR*\n\n" .
                       "Hallo *{$customerName}*,\n" .
                       "Mohon maaf, layanan internet Anda telah *DINONAKTIFKAN SEMENTARA* karena keterlambatan pembayaran tagihan sebesar *Rp " . number_format($this->customer->billing_amount, 0, ',', '.') . "*\n\n" .
                       "Silakan segera lakukan pembayaran untuk mengaktifkan kembali layanan Anda secara otomatis.\n\n" .
                       "*CARA PEMBAYARAN:*\n" .
                       "👉 " . env('FRONTEND_URL', 'https://nd-hotpot.net') . "/payment\n\n" .
                       "Hormat kami,\n" .
                       "*ND-Hotspot* 💡";

                $whatsapp->sendMessage($this->customer->whatsapp, $msg);
                Log::info("ISOLASI JOB: WhatsApp isolation notice sent to {$customerName}.");
            } catch (\Throwable $e) {
                // WA failure is non-blocking — MikroTik isolation already succeeded
                Log::warning("ISOLASI JOB: WhatsApp failed for {$customerName} (non-blocking): " . $e->getMessage());
            }

            Log::info("ISOLASI JOB: {$customerName} fully isolated (MikroTik + WA).");

        } catch (\Exception $e) {
            Log::error("ISOLASI JOB ERROR: Gagal mengisolasi {$customerName}: " . $e->getMessage());

            // If all retries exhausted, rollback is_isolated so billing:check can try again tomorrow
            if ($this->attempts() >= $this->tries) {
                Log::error("ISOLASI JOB: All {$this->tries} retries exhausted for {$customerName}. Rolling back is_isolated.");
                $this->customer->update(['is_isolated' => false]);
            }

            throw $e; // Re-throw so the queue knows to retry or fail
        }
    }
}
