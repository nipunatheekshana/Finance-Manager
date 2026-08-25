<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_profiles', function (Blueprint $table) {
            // How the user earns, and the two settings it presets.
            $table->string('income_mode', 20)->default('salaried')->after('user_id');
            $table->string('cycle_anchor', 20)->default('pay_day')->after('income_mode');
            $table->string('funding_method', 20)->default('fixed')->after('cycle_anchor');

            // The steady amount an irregular earner pays themselves each cycle.
            $table->decimal('target_draw', 15, 2)->default(0)->after('base_salary');

            // Forecasting from recent cycles: how far back, and how cautiously.
            $table->unsignedTinyInteger('forecast_months')->default(3)->after('target_draw');
            $table->unsignedTinyInteger('forecast_factor')->default(80)->after('forecast_months');
        });

        // "Salary day" only makes sense for the salaried. The column now names
        // the day a cycle starts, whatever anchors it.
        Schema::table('financial_profiles', function (Blueprint $table) {
            $table->renameColumn('salary_day', 'cycle_start_day');
        });
    }

    public function down(): void
    {
        Schema::table('financial_profiles', function (Blueprint $table) {
            $table->renameColumn('cycle_start_day', 'salary_day');
        });

        Schema::table('financial_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'income_mode',
                'cycle_anchor',
                'funding_method',
                'target_draw',
                'forecast_months',
                'forecast_factor',
            ]);
        });
    }
};
