<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->rememberToken()->after('password_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->dropRememberToken();
        });
    }
};
