<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_plans', function (Blueprint $table) {
            // Recorded per plan so history stays readable after the user
            // changes how they earn.
            $table->string('funding_method', 20)->default('fixed')->after('month');

            // The part of this plan funded out of the holding pot rather than a
            // salary. Only draw-based funding puts anything here.
            $table->decimal('drawn_amount', 15, 2)->default(0)->after('opening_balance');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_plans', function (Blueprint $table) {
            $table->dropColumn(['funding_method', 'drawn_amount']);
        });
    }
};
