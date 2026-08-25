<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_debt_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->decimal('minimum_payment', 15, 2)->default(0);
            $table->decimal('recommended_payment', 15, 2)->default(0);
            $table->decimal('planned_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['monthly_plan_id', 'debt_id']);
            $table->index(['debt_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_debt_allocations');
    }
};
