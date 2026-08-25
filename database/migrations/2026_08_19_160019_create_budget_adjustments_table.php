<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every deliberate change the user approves after an overspend.
        Schema::create('budget_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monthly_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('weekly_budget_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_weekly_budget_id')->nullable()->constrained('weekly_budgets')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();

            // next_week | buffer | category | ignore
            $table->string('type', 30);
            $table->decimal('amount', 15, 2);
            $table->decimal('original_amount', 15, 2)->nullable();
            $table->decimal('adjusted_amount', 15, 2)->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'monthly_plan_id']);
            $table->index(['weekly_budget_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_adjustments');
    }
};
