<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->unsignedBigInteger('notification_preference_id')->primary()->comment('偏好ID（全局ID）');
            $table->bigInteger('user_id')->unsigned();
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
            $table->string('channel', 30)->comment('通知通道: database, mail, broadcast');
            $table->string('type', 100)->nullable()->comment('通知类型, null=全局默认');
            $table->boolean('enabled')->default(true)->comment('是否启用');
            $table->json('options')->nullable()->comment('通道选项');
            $table->timestamps();

            $table->unique(['user_id', 'channel', 'type'], 'notif_pref_unique');
            $table->index(['user_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
