<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\MikrotikService;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

class IsolateCustomerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $customer;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }

    public function handle(MikrotikService $mikrotik)
    {
        try {
            Log::info("ISOLASI JOB: Starting isolation for {$this->customer->name}");
            
            $mikrotik->setUserStatus($this->customer->name, false);
            $mikrotik->clearUserActiveSessions($this->customer->name);
            $mikrotik->clearUserCookies($this->customer->name);

            $this->customer->is_isolated = true;
            $this->customer->save();

            Log::info("ISOLASI JOB: {$this->customer->name} berhasil diisolir di Mikrotik dan DB.");
        } catch (\Exception $e) {
            Log::error("ISOLASI JOB ERROR: Gagal mengisolasi {$this->customer->name}: " . $e->getMessage());
            throw $e;
        }
    }
}
