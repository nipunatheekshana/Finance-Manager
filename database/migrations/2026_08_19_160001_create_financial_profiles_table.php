<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('base_salary', 15, 2)->default(0);
            $table->unsignedTinyInteger('salary_day')->default(25);
            $table->boolean('has_extra_income')->default(false);
            $table->decimal('default_buffer', 15, 2)->default(0);

            // Extra-income split rules (must total 100).
            $table->unsignedTinyInteger('extra_debt_percentage')->default(50);
            $table->unsignedTinyInteger('extra_savings_percentage')->default(30);
            $table->unsignedTinyInteger('extra_spending_percentage')->default(20);

            $table->string('theme', 10)->default('system');
            $table->json('notification_settings')->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_profiles');
    }
};
