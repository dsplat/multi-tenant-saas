<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_templates', function (Blueprint $table) {
            $table->string('scope', 20)->default('system')->after('tenant_id');
            $table->string('locale', 10)->nullable()->after('variables');

            $table->index(['scope', 'type'], 'idx_scope_type');
            $table->index(['tenant_id', 'scope', 'type', 'locale'], 'idx_tenant_scope_type_locale');
        });
    }

    public function down(): void
    {
        Schema::table('mail_templates', function (Blueprint $table) {
            $table->dropIndex('idx_scope_type');
            $table->dropIndex('idx_tenant_scope_type_locale');
            $table->dropColumn(['scope', 'locale']);
        });
    }
};
