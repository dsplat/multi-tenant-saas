<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lottery_blacklists', function (Blueprint $table) {
            $table->unsignedBigInteger('blacklist_id')->primary()->comment('黑名单ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('activity_id')->comment('活动ID');
            $table->string('identifier_type', 20)->comment('标识类型: user_id/ip/device');
            $table->string('identifier', 255)->comment('标识值');
            $table->string('reason', 512)->nullable()->comment('拉黑原因');
            $table->timestamps();

            $table->index(['tenant_id', 'activity_id']);
            $table->index(['activity_id', 'identifier_type', 'identifier']);
            $table->unique(['activity_id', 'identifier_type', 'identifier'], 'lottery_blacklist_unique');

            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');
            $table->foreign('activity_id')->references('activity_id')->on('lottery_activities')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottery_blacklists');
    }
};
