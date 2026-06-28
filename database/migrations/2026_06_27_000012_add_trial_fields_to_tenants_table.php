<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('trial_extended')->default(false)->after('trial_ends_at')->comment('试用期是否已延长');
            $table->timestamp('trial_notification_sent_at')->nullable()->after('trial_extended')->comment('试用期通知发送时间');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['trial_extended', 'trial_notification_sent_at']);
        });
    }
};
