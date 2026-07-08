<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_memories', function (Blueprint $table) {
            $table->unsignedBigInteger('memory_id')->primary()->comment('记忆ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('entity_type', 100)->comment('实体类型');
            $table->unsignedBigInteger('entity_id')->comment('实体ID');
            $table->string('key', 200)->comment('记忆键');
            $table->json('value')->nullable()->comment('记忆值(JSON)');
            $table->float('weight', 8, 2)->default(1.0)->comment('权重');
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'entity_type', 'entity_id']);
            $table->index(['entity_type', 'entity_id', 'key']);
            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_memories');
    }
};
