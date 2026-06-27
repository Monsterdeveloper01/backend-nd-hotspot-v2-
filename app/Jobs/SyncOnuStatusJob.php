<?php

namespace App\Jobs;

use App\Models\OltConfig;
use App\Models\OnuNode;
use App\Services\OltService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncOnuStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $olt;

    /**
     * Create a new job instance.
     */
    public function __construct(OltConfig $olt)
    {
        $this->olt = $olt;
    }

    /**
     * Execute the job.
     */
    public function handle(OltService $oltService): void
    {
        if (!$oltService->isReachable($this->olt)) {
            Log::warning("OLT {$this->olt->name} is unreachable.");
            return;
        }

        $onus = $oltService->getAllOnu($this->olt);

        foreach ($onus as $onuData) {
            $existingNode = OnuNode::where('olt_id', $this->olt->id)
                ->where('onu_index', $onuData['onu_index'])
                ->first();

            $wasOnline = $existingNode ? $existingNode->status === 'online' : false;

            $node = OnuNode::updateOrCreate(
                ['olt_id' => $this->olt->id, 'onu_index' => $onuData['onu_index']],
                [
                    'serial_number' => $onuData['serial_number'],
                    'last_signal' => $onuData['signal'],
                    'status' => $onuData['status'],
                    'last_check' => now(),
                    'last_seen_at' => $onuData['status'] === 'online' ? now() : ($existingNode->last_seen_at ?? null),
                ]
            );

            if ($wasOnline && $onuData['status'] === 'offline') {
                OnuDownAlertJob::dispatch($node)->delay(now()->addMinutes(2));
            }
        }

        $this->olt->update(['last_synced_at' => now()]);
    }
}
