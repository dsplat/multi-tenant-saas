<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dead_letters', function (Blueprint $table) {
            $table->foreign('subscription_id')
                ->references('event_subscription_id')
                ->on('event_subscriptions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dead_letters', function (Blueprint $table) {
            $table->dropForeign(['subscription_id']);
        });
    }
};
