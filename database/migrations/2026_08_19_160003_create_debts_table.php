<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 30)->default('other');
            $table->decimal('original_amount', 15, 2)->default(0);
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->decimal('credit_limit', 15, 2)->nullable();

            // Annual percentage rate, e.g. 28.00 for 28%. Null means "unknown".
            $table->decimal('interest_rate', 6, 3)->nullable();

            $table->decimal('minimum_payment', 15, 2)->default(0);
            $table->decimal('planned_payment', 15, 2)->default(0);
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->unsignedSmallInteger('remaining_installments')->nullable();
            $table->decimal('installment_amount', 15, 2)->nullable();
            $table->decimal('early_settlement_amount', 15, 2)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debts');
    }
};
