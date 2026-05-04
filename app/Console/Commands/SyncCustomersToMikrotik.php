<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Services\MikrotikService;

class SyncCustomersToMikrotik extends Command
{
    protected $signature = 'customer:sync';
    protected $description = 'Sync all customers to Mikrotik hotspot users';

    protected $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        parent::__construct();
        $this->mikrotik = $mikrotik;
    }

    public function handle()
    {
        $this->info("Starting customer synchronization...");

        $customers = Customer::all();

        foreach ($customers as $c) {
            $this->info("Processing: {$c->name}");
            
            // Re-create user in Mikrotik
            // If they are isolated in DB, we create them as disabled
            $result = $this->mikrotik->createUser([
                'username' => $c->name,
                'password' => '', // Usually no password for hotspot users by name
                'profile' => 'default', // You can change this if you have a specific profile for monthly customers
                'comment' => 'Synced Monthly Customer',
            ]);

            // If user exists, update status based on isolation
            $this->mikrotik->setUserStatus($c->name, !$c->is_isolated);
            
            $this->info("Customer {$c->name} synced " . ($c->is_isolated ? "(Disabled/Isolated)" : "(Enabled/Active)"));
        }

        $this->info("Synchronization finished!");
    }
}
