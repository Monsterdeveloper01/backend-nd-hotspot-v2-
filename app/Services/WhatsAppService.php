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
