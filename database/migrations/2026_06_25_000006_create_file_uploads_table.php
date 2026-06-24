<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_uploads', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->nullable()->index();
            $table->bigInteger('user_id')->unsigned()->nullable()->index();
            $table->string('disk', 20)->default('local')->comment('存储磁盘: local/s3/oss');
            $table->string('path', 500)->comment('存储路径');
            $table->string('filename', 255)->comment('原始文件名');
            $table->string('mime_type', 100)->nullable();
            $table->bigInteger('size')->unsigned()->default(0)->comment('文件大小(字节)');
            $table->string('hash', 64)->nullable()->index()->comment('文件哈希，用于去重');
            $table->string('category', 50)->default('general')->comment('文件分类');
            $table->boolean('is_public')->default(false)->comment('是否公开可访问');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'category']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_uploads');
    }
};
