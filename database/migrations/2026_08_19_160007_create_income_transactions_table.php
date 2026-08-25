<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('income_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('monthly_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('received_date');

            // "base" counts toward the expected salary, "extra" feeds the bonus split.
            $table->string('type', 20)->default('base');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'received_date']);
            $table->index(['monthly_plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_transactions');
    }
};
