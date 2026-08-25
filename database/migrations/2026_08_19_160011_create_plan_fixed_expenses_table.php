<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A snapshot of the recurring bills pulled into one monthly plan, so the
        // user can edit / skip / postpone a bill for that month only without
        // mutating the underlying recurring transaction.
        Schema::create('plan_fixed_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->decimal('amount', 15, 2);
            $table->decimal('actual_amount', 15, 2)->nullable();
            $table->unsignedTinyInteger('occurrences')->default(1);
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('planned');
            $table->date('postponed_to')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['monthly_plan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_fixed_expenses');
    }
};
