<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much of the extra income has already been split across debts and
 * savings. Without it, recording the salary a second time distributed the
 * same extra all over again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_plans', function (Blueprint $table) {
            $table->decimal('extra_income_applied', 15, 2)->default(0)->after('extra_income');
        });

        // Existing plans have already had their extra split exactly once.
        DB::table('monthly_plans')->update(['extra_income_applied' => DB::raw('extra_income')]);
    }

    public function down(): void
    {
        Schema::table('monthly_plans', function (Blueprint $table) {
            $table->dropColumn('extra_income_applied');
        });
    }
};
