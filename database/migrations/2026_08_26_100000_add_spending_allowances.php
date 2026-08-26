<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A spending allowance is money set aside for a category that is spent
        // little by little rather than in one payment — fuel, groceries,
        // eating out. It is reserved in the plan like a bill, but drawn down
        // like day-to-day spending.
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_allowance')->default(false)->after('monthly_budget');
        });

        Schema::table('budget_categories', function (Blueprint $table) {
            // Snapshotted per cycle: turning an allowance off later must not
            // rewrite what past plans reserved.
            $table->boolean('is_allowance')->default(false)->after('category_id');
        });

        Schema::table('monthly_plans', function (Blueprint $table) {
            $table->decimal('allowances', 15, 2)->default(0)->after('fixed_expenses');
        });
    }

    public function down(): void
    {
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('is_allowance'));
        Schema::table('budget_categories', fn (Blueprint $table) => $table->dropColumn('is_allowance'));
        Schema::table('monthly_plans', fn (Blueprint $table) => $table->dropColumn('allowances'));
    }
};
