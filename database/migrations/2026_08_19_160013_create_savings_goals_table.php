<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('icon', 50)->default('piggy-bank');
            $table->decimal('target_amount', 15, 2);
            $table->decimal('current_amount', 15, 2)->default(0);
            $table->decimal('monthly_target', 15, 2)->default(0);

            // fixed | salary_percentage | extra_percentage | custom
            $table->string('allocation_type', 30)->default('fixed');
            $table->decimal('allocation_value', 15, 2)->default(0);

            $table->date('target_date')->nullable();
            $table->unsignedTinyInteger('priority')->default(3);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_goals');
    }
};
