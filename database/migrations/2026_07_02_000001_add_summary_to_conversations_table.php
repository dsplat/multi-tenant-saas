<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->text('summary')->nullable()->after('message_count')->comment('会话摘要');
            $table->timestamp('summary_updated_at')->nullable()->after('summary')->comment('摘要更新时间');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['summary', 'summary_updated_at']);
        });
    }
};
