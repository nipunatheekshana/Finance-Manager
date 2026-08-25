<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_savings_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('savings_goal_id')->constrained()->cascadeOnDelete();
            $table->decimal('recommended_amount', 15, 2)->default(0);
            $table->decimal('planned_amount', 15, 2)->default(0);
            $table->decimal('saved_amount', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['monthly_plan_id', 'savings_goal_id']);
            $table->index(['savings_goal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_savings_allocations');
    }
};
