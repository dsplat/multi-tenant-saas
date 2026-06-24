<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedBigInteger('subscription_plan_id')->nullable()->after('subscription_plan');
            $table->boolean('auto_renew')->default(false)->after('subscription_expires_at');
            $table->timestamp('trial_ends_at')->nullable()->after('auto_renew');

            $table->foreign('subscription_plan_id')->references('id')->on('subscription_plans')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['subscription_plan_id']);
            $table->dropColumn(['subscription_plan_id', 'auto_renew', 'trial_ends_at']);
        });
    }
};
