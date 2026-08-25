<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('severity', 20)->default('info');
            $table->string('title');
            $table->text('message');
            $table->string('action_label')->nullable();
            $table->string('action_route')->nullable();
            $table->json('data')->nullable();

            // Type + reference + day identify one alert, so re-running the
            // generator never stacks duplicates. Reference scopes the alert to
            // the thing it is about (a category id, a debt id, ...) and is an
            // empty string rather than null so the unique index still bites.
            $table->string('reference', 60)->default('');
            $table->date('triggered_on');

            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'dismissed_at']);
            $table->unique(['user_id', 'type', 'reference', 'triggered_on'], 'alerts_user_type_ref_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_alerts');
    }
};
