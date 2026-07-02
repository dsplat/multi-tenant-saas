<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_memories', function (Blueprint $table) {
            $table->unsignedBigInteger('memory_id')->primary()->comment('记忆ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('type', 50)->default('general')->comment('记忆类型');
            $table->string('key', 255)->comment('记忆键名');
            $table->json('value')->nullable()->comment('记忆值');
            $table->float('weight')->default(1.0)->comment('权重');
            $table->timestamp('last_accessed_at')->nullable()->comment('最后访问时间');
            $table->timestamps();

            $table->unique(['tenant_id', 'type', 'key']);
            $table->index(['tenant_id', 'type']);
            $table->index('weight');
        });

        Schema::create('entity_memories', function (Blueprint $table) {
            $table->unsignedBigInteger('memory_id')->primary()->comment('记忆ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('entity_type', 50)->comment('实体类型');
            $table->unsignedBigInteger('entity_id')->comment('实体ID');
            $table->string('key', 255)->comment('记忆键名');
            $table->json('value')->nullable()->comment('记忆值');
            $table->float('weight')->default(1.0)->comment('权重');
            $table->timestamp('last_accessed_at')->nullable()->comment('最后访问时间');
            $table->timestamps();

            $table->unique(['tenant_id', 'entity_type', 'entity_id', 'key']);
            $table->index(['tenant_id', 'entity_type', 'entity_id']);
            $table->index('weight');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_memories');
        Schema::dropIfExists('tenant_memories');
    }
};
