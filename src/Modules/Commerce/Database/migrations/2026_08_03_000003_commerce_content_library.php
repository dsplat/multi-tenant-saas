<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commerce 模块（Phase 3：平台内容库）
 *
 * - platform_contents: 平台内容条目（文章/视频/音频/文件），平台级无 tenant_id
 * - platform_content_packs: 内容包（content_pack SKU payload.pack_id 指向）
 * - platform_content_pack_items: 包⇄内容关联（多对多 + 排序）
 *
 * 展示链路（Layer B）归下游项目；本层只做库与包的存储和管理。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_contents')) {
            Schema::create('platform_contents', function (Blueprint $table) {
                $table->unsignedBigInteger('content_id')->primary()->comment('内容ID（全局ID）');
                $table->string('title', 200)->comment('标题');
                $table->string('type', 30)->default('article')->comment('article|video|audio|image|file');
                $table->text('body')->nullable()->comment('正文（富文本/纯文本）');
                $table->string('file_url', 500)->nullable()->comment('媒体文件地址');
                $table->string('cover_url', 500)->nullable()->comment('封面');
                $table->json('tags')->nullable()->comment('标签');
                $table->string('status', 20)->default('draft')->comment('draft|published|retired');
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->index(['status', 'type']);
            });
        }

        if (! Schema::hasTable('platform_content_packs')) {
            Schema::create('platform_content_packs', function (Blueprint $table) {
                $table->unsignedBigInteger('pack_id')->primary()->comment('内容包ID（全局ID）');
                $table->string('name', 200)->comment('包名');
                $table->string('description', 500)->nullable();
                $table->string('cover_url', 500)->nullable();
                $table->string('status', 20)->default('draft')->comment('draft|active|retired');
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->index('status');
            });
        }

        if (! Schema::hasTable('platform_content_pack_items')) {
            Schema::create('platform_content_pack_items', function (Blueprint $table) {
                $table->unsignedBigInteger('pack_id');
                $table->unsignedBigInteger('content_id');
                $table->integer('sort_order')->default(0);
                $table->primary(['pack_id', 'content_id']);
                $table->index('content_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_content_pack_items');
        Schema::dropIfExists('platform_content_packs');
        Schema::dropIfExists('platform_contents');
    }
};
