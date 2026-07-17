<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix #9: Add resolved_at to alerts table
 * AlertService uses whereNull('resolved_at') but migration was missing the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->timestamp('resolved_at')->nullable()->after('triggered_at');
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn('resolved_at');
        });
    }
};
