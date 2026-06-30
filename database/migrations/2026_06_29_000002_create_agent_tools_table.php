<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tools', function (Blueprint $table) {
            $table->unsignedBigInteger('tool_id')->primary()->comment('工具 ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('tenant_id')->default(0)->comment('租户ID（0=全局工具）');
            $table->string('name', 100)->comment('工具名称');
            $table->string('slug', 100)->unique()->comment('工具唯一标识');
            $table->text('description')->comment('工具描述');
            $table->string('category', 50)->nullable()->comment('工具分类');
            $table->json('parameters_schema')->comment('参数 JSON Schema');
            $table->string('handler_class', 255)->comment('处理类全限定名');
            $table->boolean('enabled')->default(true)->comment('是否启用');
            $table->timestamps();

            $table->index(['tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tools');
    }
};
