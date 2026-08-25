<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CardPaymentMethodService;
use Illuminate\Console\Command;

/**
 * Backfill for accounts whose credit cards were created before every card got
 * its own payment method. Idempotent: cards that are already linked are left
 * untouched.
 */
class LinkCardPaymentMethods extends Command
{
    protected $signature = 'finance:link-cards {--user= : Only this user}';

    protected $description = 'Give every credit-card debt its own linked payment method';

    public function handle(CardPaymentMethodService $cards): int
    {
        $query = User::query()->whereHas('debts', fn ($q) => $q->where('type', 'credit_card'));

        if ($userId = $this->option('user')) {
            $query->whereKey($userId);
        }

        $linked = 0;
        $touched = 0;

        $query->chunkById(100, function ($users) use ($cards, &$linked, &$touched) {
            foreach ($users as $user) {
                $count = $cards->backfillFor($user);
                $linked += $count;
                $touched++;
            }
        });

        $this->info("Checked {$touched} ".($touched === 1 ? 'account' : 'accounts').", {$linked} card ".($linked === 1 ? 'link' : 'links').' in place.');

        return self::SUCCESS;
    }
}
