<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('attachment_id')->primary()->comment('附件 ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('conversation_id')->comment('会话 ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('uploaded_by')->comment('上传者用户ID');
            $table->unsignedBigInteger('file_upload_id')->nullable()->comment('关联的文件上传 ID');
            $table->string('filename', 255)->comment('原始文件名');
            $table->string('mime_type', 100)->nullable()->comment('MIME 类型');
            $table->unsignedBigInteger('size')->default(0)->comment('文件大小（字节）');
            $table->string('disk', 20)->default('local')->comment('存储磁盘: local/s3/oss');
            $table->string('path', 500)->comment('存储路径');
            $table->json('metadata')->nullable()->comment('元数据');
            $table->timestamps();

            $table->index(['conversation_id']);
            $table->index(['tenant_id']);
            $table->index(['uploaded_by']);
            $table->index(['file_upload_id']);

            $table->foreign('conversation_id')->references('conversation_id')->on('conversations')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('user_id')->on('users');
            $table->foreign('tenant_id')->references('tenant_id')->on('tenants');
            $table->foreign('file_upload_id')->references('file_upload_id')->on('file_uploads');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
