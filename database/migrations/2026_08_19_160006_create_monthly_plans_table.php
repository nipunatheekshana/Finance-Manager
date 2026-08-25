<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');

            $table->decimal('expected_income', 15, 2)->default(0);
            $table->decimal('actual_income', 15, 2)->nullable();
            $table->decimal('extra_income', 15, 2)->default(0);
            $table->decimal('fixed_expenses', 15, 2)->default(0);
            $table->decimal('debt_payment', 15, 2)->default(0);
            $table->decimal('savings', 15, 2)->default(0);
            $table->decimal('spending_budget', 15, 2)->default(0);
            $table->decimal('buffer', 15, 2)->default(0);
            $table->decimal('buffer_used', 15, 2)->default(0);

            // The salary cycle this plan covers. Driven by the profile salary day,
            // so a plan can legitimately span two calendar months.
            $table->date('cycle_start_date');
            $table->date('cycle_end_date');

            $table->string('status', 20)->default('draft');
            $table->boolean('allow_deficit')->default(false);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'year', 'month']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'year', 'month']);
            $table->index(['user_id', 'cycle_start_date', 'cycle_end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_plans');
    }
};
