<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\OnuNode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OnuDownAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $node;

    /**
     * Create a new job instance.
     */
    public function __construct(OnuNode $node)
    {
        $this->node = $node;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Check if still offline before sending alert
        $this->node->refresh();
        if ($this->node->status === 'online') {
            return; // Recovered within 2 minutes
        }

        $adminWa = env('ADMIN_WHATSAPP');
        $waUrl = config('services.whatsapp.url') . '/send';
        $waToken = config('services.whatsapp.token', env('WA_API_TOKEN', ''));

        $oltName = $this->node->olt->name ?? 'Unknown OLT';
        $adminMessage = "*🚨 ONU OFFLINE ALERT 🚨*\n"
            . "OLT: {$oltName}\n"
            . "ONU: {$this->node->onu_index}\n"
            . "SN: {$this->node->serial_number}\n"
            . "Time: " . now()->format('Y-m-d H:i:s');

        // Send to Admin
        if ($adminWa) {
            $this->sendWhatsappMessage($waUrl, $waToken, $adminWa, $adminMessage);
        }

        // Send to Customer
        $customer = Customer::where('onu_serial', $this->node->serial_number)->first();
        if ($customer && $customer->phone) {
            $customerMessage = "Halo {$customer->name},\n"
                . "Koneksi internet Anda saat ini terdeteksi terputus (ONU Offline). "
                . "Mohon cek apakah modem menyala. Jika menyala dan masih tidak ada internet, "
                . "mohon tunggu sementara tim kami sedang melakukan pengecekan.";
            
            $this->sendWhatsappMessage($waUrl, $waToken, $customer->phone, $customerMessage);
        }
    }

    private function sendWhatsappMessage($url, $token, $phone, $message)
    {
        try {
            Http::withToken($token)->post($url, [
                'target' => $phone,
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send WA message: " . $e->getMessage());
        }
    }
}
