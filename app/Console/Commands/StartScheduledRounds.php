<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Event;
use App\Models\Round;
use App\Services\GroupingService;

class StartScheduledRounds extends Command
{
    protected $signature   = 'rounds:start-scheduled';
    protected $description = 'Auto-open Round 1, auto-close expired rounds, auto-open next round after waiting period.';

    public function __construct(protected GroupingService $grouping)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $this->initializeNewEvents();
        $this->closeExpiredRounds();
        $this->openNextRoundsAfterWait();
    }

    /**
     * Pre-create all rounds as 'waiting', open Round 1.
     * Runs when event started_at is reached and no rounds exist yet.
     */
    private function initializeNewEvents(): void
    {
        $events = Event::whereIn('status', ['draft', 'live'])
            ->where('started_at', '<=', now())
            ->whereDoesntHave('rounds')
            ->get();

        foreach ($events as $event) {
            if ($event->status === 'draft') {
                $event->update(['status' => 'live']);
                $this->info("Event #{$event->id} set to LIVE");
            }

            $totalRounds = (int) $event->rounds_count;

            for ($i = 1; $i <= $totalRounds; $i++) {
                Round::create([
                    'event_id'     => $event->id,
                    'round_number' => $i,
                    'status'       => 'waiting',
                    'opened_at'    => null,
                    'closed_at'    => null,
                ]);
            }

            $round1 = $event->rounds()->where('round_number', 1)->first();
            $round1->update(['status' => 'open', 'opened_at' => now()]);

            $this->info("Event #{$event->id} — {$totalRounds} rounds created, Round 1 opened.");
        }
    }

    /**
     * Auto-close open rounds whose duration has expired (from opened_at).
     * duration stored as "HH:MM" on the event.
     * If duration is not set or 0 → host closes manually.
     */
    private function closeExpiredRounds(): void
    {
        $events = Event::where('status', 'live')
            ->whereHas('rounds', fn($q) => $q->where('status', 'open'))
            ->get();

        foreach ($events as $event) {
            $durationSecs = $this->parseDuration($event->duration);
            if ($durationSecs <= 0) continue;

            $openRound = $event->rounds()->where('status', 'open')->first();
            if (!$openRound || !$openRound->opened_at) continue;

            $elapsed = (int) $openRound->opened_at->diffInSeconds(now());
            if ($elapsed < $durationSecs) continue;

            $openRound->update(['status' => 'closed', 'closed_at' => now()]);

            $noMoreActive = !$event->rounds()->whereIn('status', ['waiting', 'open'])->exists();
            if ($noMoreActive) {
                $event->update(['status' => 'finished']);
                $this->info("Event #{$event->id} finished — all rounds complete.");
            } else {
                $this->info("Round {$openRound->round_number} auto-closed for Event #{$event->id}.");
            }
        }
    }

    /**
     * Auto-open next waiting round after round_time seconds since last close.
     * round_time stored in seconds on the event.
     * If round_time is 0 → host opens manually.
     */
    private function openNextRoundsAfterWait(): void
    {
        $events = Event::where('status', 'live')
            ->whereHas('rounds', fn($q) => $q->where('status', 'closed'))
            ->whereHas('rounds', fn($q) => $q->where('status', 'waiting'))
            ->whereDoesntHave('rounds', fn($q) => $q->where('status', 'open'))
            ->get();

        foreach ($events as $event) {
            $roundTime = (int) $event->round_time;
            if ($roundTime <= 0) continue;

            $lastClosed = $event->rounds()
                ->where('status', 'closed')
                ->orderByDesc('round_number')
                ->first();

            if (!$lastClosed || !$lastClosed->closed_at) continue;

            $secondsSinceClosed = (int) $lastClosed->closed_at->diffInSeconds(now());
            if ($secondsSinceClosed < $roundTime) continue;

            $nextRound = $event->rounds()
                ->where('status', 'waiting')
                ->orderBy('round_number')
                ->first();

            if (!$nextRound) continue;

            $nextRound->update(['status' => 'open', 'opened_at' => now()]);

            $this->info("Round {$nextRound->round_number} auto-opened for Event #{$event->id}.");
        }
    }

    private function parseDuration(?string $duration): int
    {
        if (!$duration) return 0;
        $parts = explode(':', $duration);
        if (count($parts) < 2) return 0;
        [$hh, $mm] = array_map('intval', $parts);
        return ($hh * 60 + $mm) * 60;
    }
}