<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('week_number');
            $table->date('start_date');
            $table->date('end_date');

            $table->decimal('budget_amount', 15, 2)->default(0);

            // Set only when the user explicitly approves an adjustment.
            $table->decimal('adjusted_amount', 15, 2)->nullable();

            // Denormalised cache; expenses remain the source of truth.
            $table->decimal('spent_amount', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['monthly_plan_id', 'week_number']);
            $table->index(['monthly_plan_id']);
            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_budgets');
    }
};
