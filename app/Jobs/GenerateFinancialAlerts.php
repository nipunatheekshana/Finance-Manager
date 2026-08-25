<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AlertService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Refreshes one user's dashboard alerts.
 *
 * Queued per user so a large account base does not turn the daily sweep into
 * one long-running command, and so a failure for one user cannot stop the rest.
 */
class GenerateFinancialAlerts implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $userId) {}

    public function handle(AlertService $alerts): void
    {
        $user = User::with('financialProfile')->find($this->userId);

        if ($user === null) {
            return;
        }

        $alerts->generateFor($user);
    }

    /** One pending job per user is enough. */
    public function uniqueId(): string
    {
        return (string) $this->userId;
    }
}
