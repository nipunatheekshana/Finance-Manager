<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('income_sources', function (Blueprint $table) {
            $table->string('kind', 20)->default('other')->after('type');
            $table->string('cadence', 20)->default('irregular')->after('kind');
            $table->string('client_name')->nullable()->after('cadence');
            $table->timestamp('archived_at')->nullable()->after('active');

            $table->index(['user_id', 'kind']);
        });

        Schema::table('income_transactions', function (Blueprint $table) {
            // Expected and invoiced money is not money you have. Only received
            // income counts toward what can be spent.
            $table->string('status', 20)->default('received')->after('type');
            $table->date('due_date')->nullable()->after('received_date');
            $table->string('reference', 60)->nullable()->after('description');

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::table('income_sources', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'kind']);
            $table->dropColumn(['kind', 'cadence', 'client_name', 'archived_at']);
        });

        Schema::table('income_transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['user_id', 'due_date']);
            $table->dropColumn(['status', 'due_date', 'reference']);
        });
    }
};
