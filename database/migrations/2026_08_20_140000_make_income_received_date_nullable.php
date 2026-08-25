<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Income that has only been expected or invoiced has not been received,
        // so it cannot have a received date yet.
        Schema::table('income_transactions', function (Blueprint $table) {
            $table->date('received_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('income_transactions', function (Blueprint $table) {
            $table->date('received_date')->nullable(false)->change();
        });
    }
};
