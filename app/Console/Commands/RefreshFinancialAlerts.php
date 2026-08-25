<?php

namespace App\Console\Commands;

use App\Jobs\GenerateFinancialAlerts;
use App\Models\User;
use Illuminate\Console\Command;

class RefreshFinancialAlerts extends Command
{
    protected $signature = 'finance:refresh-alerts
                            {--user= : Only refresh a single user}
                            {--sync : Run inline instead of queueing}';

    protected $description = 'Rebuild dashboard alerts for salary day, bills, debts, budgets and savings';

    public function handle(): int
    {
        $query = User::query()->select('id');

        if ($userId = $this->option('user')) {
            $query->whereKey($userId);
        }

        $count = 0;

        $query->chunkById(200, function ($users) use (&$count) {
            foreach ($users as $user) {
                $job = new GenerateFinancialAlerts($user->id);

                $this->option('sync')
                    ? dispatch_sync($job)
                    : dispatch($job);

                $count++;
            }
        });

        $this->info("Queued alert refresh for {$count} ".($count === 1 ? 'user' : 'users').'.');

        return self::SUCCESS;
    }
}
