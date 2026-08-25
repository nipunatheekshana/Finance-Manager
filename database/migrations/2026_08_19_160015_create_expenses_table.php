<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('monthly_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('weekly_budget_id')->nullable()->constrained()->nullOnDelete();

            // Set when the expense was charged to a credit card / debt account.
            $table->foreignId('debt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recurring_transaction_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('amount', 15, 2);
            $table->date('expense_date');
            $table->string('description')->nullable();

            // Client-generated id used to make offline sync idempotent.
            $table->uuid('client_uuid')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'expense_date']);
            $table->index(['user_id', 'category_id', 'expense_date']);
            $table->index(['user_id', 'payment_method_id']);
            $table->index(['category_id']);
            $table->index(['monthly_plan_id']);
            $table->index(['weekly_budget_id']);
            $table->index(['debt_id']);
            $table->unique(['user_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
