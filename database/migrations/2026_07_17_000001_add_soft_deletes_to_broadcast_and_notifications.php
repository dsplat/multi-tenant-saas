<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix #5: Add deleted_at to broadcast_events and in_app_notifications
 * Models use SoftDeletes but migration was missing the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('in_app_notifications', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('broadcast_events', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('in_app_notifications', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('broadcast_events', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
