<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monthly_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('payment_date');
            $table->decimal('interest_amount', 15, 2)->nullable();
            $table->decimal('principal_amount', 15, 2)->nullable();
            $table->decimal('balance_after', 15, 2)->nullable();
            $table->boolean('reduced_installment')->default(false);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['debt_id', 'payment_date']);
            $table->index(['monthly_plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_payments');
    }
};
