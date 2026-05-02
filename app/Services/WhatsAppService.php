<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WhatsAppService
{
    protected $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('WHATSAPP_GATEWAY_URL', 'http://localhost:5000');
    }

    public function sendMessage($number, $message)
    {
        // Normalize number to international format (62 for Indonesia)
        $number = preg_replace('/\D/', '', $number);
        if (str_starts_with($number, '0')) {
            $number = '62' . substr($number, 1);
        } elseif (!str_starts_with($number, '62')) {
            $number = '62' . $number;
        }

        try {
            $response = Http::post("{$this->baseUrl}/send-message", [
                'number' => $number,
                'message' => $message
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            \Log::error("WhatsApp Gateway Error: " . $e->getMessage());
            return false;
        }
    }
}
