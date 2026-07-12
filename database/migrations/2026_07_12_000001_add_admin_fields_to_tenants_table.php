<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'admin_id')) {
                $table->unsignedBigInteger('admin_id')->nullable()->after('description')->comment('管理员用户ID');
            }
            if (! Schema::hasColumn('tenants', 'admin_name')) {
                $table->string('admin_name', 100)->nullable()->after('admin_id')->comment('管理员姓名');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['admin_id', 'admin_name']);
        });
    }
};
