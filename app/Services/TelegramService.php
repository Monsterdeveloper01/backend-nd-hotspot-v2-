<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TelegramService
{
    protected $token;
    protected $chatId;

    public function __construct()
    {
        $this->token = env('TELEGRAM_BOT_TOKEN');
        $this->chatId = env('TELEGRAM_CHAT_ID');
    }

    public function sendMessage($message)
    {
        if (!$this->token || !$this->chatId) {
            return false;
        }

        $ids = explode(',', $this->chatId);
        $success = true;

        foreach ($ids as $id) {
            $id = trim($id);
            try {
                $response = Http::post("https://api.telegram.org/bot{$this->token}/sendMessage", [
                    'chat_id' => $id,
                    'text' => $message,
                    'parse_mode' => 'HTML'
                ]);
                if (!$response->successful()) $success = false;
            } catch (\Exception $e) {
                \Log::error("Telegram Notification Error for ID {$id}: " . $e->getMessage());
                $success = false;
            }
        }

        return $success;
    }
}
