<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Safety check: warn if users still have role values set
        // (data should have been migrated to operators/operator_tenants first)
        $usersWithRole = DB::table('users')->whereNotNull('role')->count();
        if ($usersWithRole > 0) {
            report("Warning: {$usersWithRole} users still have 'role' set. Ensure data migration to operators/operator_tenants is complete.");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });

        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('platform_user')->after('phone');
            $table->index('role');
        });

        Schema::table('tenant_users', function (Blueprint $table) {
            $table->string('role', 50)->default('end_user')->after('user_id');
            $table->index('role');
        });
    }
};
