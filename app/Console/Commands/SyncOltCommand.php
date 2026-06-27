<?php

namespace App\Console\Commands;

use App\Jobs\SyncOnuStatusJob;
use App\Models\OltConfig;
use Illuminate\Console\Command;

class SyncOltCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'olt:sync {olt_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync ONU status from OLT via SNMP';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $oltId = $this->argument('olt_id');

        if ($oltId) {
            $olts = OltConfig::where('id', $oltId)->where('is_active', true)->get();
        } else {
            $olts = OltConfig::where('is_active', true)->get();
        }

        if ($olts->isEmpty()) {
            $this->info('No active OLTs found.');
            return;
        }

        foreach ($olts as $olt) {
            $this->info("Syncing OLT: {$olt->name}");
            SyncOnuStatusJob::dispatchSync($olt);
        }

        $this->info('Sync complete.');
    }
}
