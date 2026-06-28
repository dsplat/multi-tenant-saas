<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->smallInteger('onboarding_step')->default(0)->after('status')->comment('当前 onboarding 步骤');
            $table->boolean('onboarding_completed')->default(false)->after('onboarding_step')->comment('onboarding 是否已完成');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['onboarding_step', 'onboarding_completed']);
        });
    }
};
