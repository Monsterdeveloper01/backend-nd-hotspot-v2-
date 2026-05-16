<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

class SendWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $phone;
    public $message;
    public $logPrefix;

    public function __construct($phone, $message, $logPrefix = 'WA')
    {
        $this->phone = $phone;
        $this->message = $message;
        $this->logPrefix = $logPrefix;
    }

    public function handle(WhatsAppService $whatsapp)
    {
        try {
            $res = $whatsapp->sendMessage($this->phone, $this->message);
            if ($res) {
                Log::info("{$this->logPrefix} Sent successfully to {$this->phone}.");
            } else {
                Log::error("{$this->logPrefix} Failed to {$this->phone}: Gateway returned false.");
            }
        } catch (\Exception $e) {
            Log::error("{$this->logPrefix} Exception to {$this->phone}: " . $e->getMessage());
            throw $e; // Re-throw agar job masuk ke failed_jobs jika error kritis
        }
    }
}
