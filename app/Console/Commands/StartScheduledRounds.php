<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Event;
use App\Models\Round;

class StartScheduledRounds extends Command
{
    protected $signature   = 'rounds:start-scheduled';
    protected $description = 'Auto-open Round 1 for events whose start time has arrived';

    public function handle(): void
    {
        // Find DRAFT or LIVE events where started_at has passed and no rounds exist yet
        $events = Event::whereIn('status', ['draft', 'live'])
            ->where('started_at', '<=', now())
            ->whereDoesntHave('rounds')
            ->get();

        foreach ($events as $event) {
            // Auto set to live if still draft
            if ($event->status === 'draft') {
                $event->update(['status' => 'live']);
                $this->info("Event #{$event->id} — {$event->name} set to LIVE");
            }

            Round::create([
                'event_id'     => $event->id,
                'round_number' => 1,
                'status'       => 'open',
                'opened_at'    => now(),
                'closed_at'    => null,
            ]);

            $this->info("Round 1 opened for Event #{$event->id} — {$event->name}");
        }
    }
}