<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('WHATSAPP_GATEWAY_URL', 'http://localhost:5000');
    }

    public function sendMessage($number, $message, $context = 'Notifikasi Sistem')
    {
        // Normalize number to international format (62 for Indonesia)
        $number = preg_replace('/\D/', '', $number);
        if (str_starts_with($number, '0')) {
            $number = '62' . substr($number, 1);
        } elseif (!str_starts_with($number, '62')) {
            $number = '62' . $number;
        }

        try {
            $response = Http::timeout(10)->post("{$this->baseUrl}/send-message", [
                'number' => $number,
                'message' => $message
            ]);

            if ($response->successful()) {
                return true;
            }

            $reason = "Gateway response status [{$response->status()}]: " . mb_substr($response->body(), 0, 255);
            Log::error("WhatsApp Gateway Returned Non-200: " . $reason);

            // Log incident to dashboard
            IncidentLoggerService::logWhatsAppFailure($number, $message, $reason, $context);

            return false;
        } catch (\Exception $e) {
            $reason = "Gateway Exception: " . $e->getMessage();
            Log::error("WhatsApp Gateway Error: " . $reason);

            // Log incident to dashboard
            IncidentLoggerService::logWhatsAppFailure($number, $message, $reason, $context);

            return false;
        }
    }
}