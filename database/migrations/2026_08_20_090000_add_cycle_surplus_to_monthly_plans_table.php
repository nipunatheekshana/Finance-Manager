<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_plans', function (Blueprint $table) {
            // Money carried in from the previous cycle's leftover. Spendable,
            // so it counts toward this plan's total income.
            $table->decimal('opening_balance', 15, 2)->default(0)->after('extra_income');

            // Money this cycle handed forward to the next one.
            $table->decimal('carried_forward', 15, 2)->default(0)->after('buffer_used');

            // What was left when the user resolved the surplus, and when they
            // did. Null means the cycle's leftover has not been dealt with yet.
            $table->decimal('surplus_amount', 15, 2)->nullable()->after('carried_forward');
            $table->timestamp('surplus_resolved_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_plans', function (Blueprint $table) {
            $table->dropColumn([
                'opening_balance',
                'carried_forward',
                'surplus_amount',
                'surplus_resolved_at',
            ]);
        });
    }
};
