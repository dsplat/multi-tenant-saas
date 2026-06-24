<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->index();
            $table->bigInteger('tenant_id')->unsigned()->nullable()->index();
            $table->unsignedInteger('apisvr_token_id')->comment('new-api 后端 token ID');
            $table->text('apisvr_key')->comment('sk-xxx 格式的完整 API Key');
            $table->integer('remain_quota_cache')->default(0);
            $table->integer('used_quota_cache')->default(0);
            $table->timestamp('quota_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('user_id');
        });

        Schema::create('user_api_token_histories', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->index();
            $table->unsignedInteger('apisvr_token_id');
            $table->string('apisvr_key_masked', 100)->comment('掩码后的旧 Key');
            $table->integer('quota_at_rotation')->default(0);
            $table->string('reason', 50)->comment('leaked|admin_reset|user_request');
            $table->bigInteger('rotated_by')->unsigned()->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_api_token_histories');
        Schema::dropIfExists('user_api_tokens');
    }
};
