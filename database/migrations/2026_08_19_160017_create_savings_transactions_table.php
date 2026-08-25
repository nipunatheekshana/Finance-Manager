<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('savings_goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monthly_plan_id')->nullable()->constrained()->nullOnDelete();

            // deposit | withdrawal | transfer_in | transfer_out
            $table->string('type', 20);
            $table->decimal('amount', 15, 2);
            $table->date('transaction_date');
            $table->string('description')->nullable();

            // The counterpart goal for transfers.
            $table->foreignId('related_goal_id')->nullable()->constrained('savings_goals')->nullOnDelete();
            $table->timestamps();

            $table->index(['savings_goal_id', 'transaction_date']);
            $table->index(['user_id', 'transaction_date']);
            $table->index(['monthly_plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_transactions');
    }
};
