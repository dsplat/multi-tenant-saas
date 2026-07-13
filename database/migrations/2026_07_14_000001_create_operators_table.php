<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table) {
            $table->bigInteger('operator_id')->unsigned()->primary();
            $table->string('email', 255);
            $table->string('name', 255);
            $table->string('password', 255)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('avatar', 500)->nullable();
            $table->string('scope', 20);
            $table->boolean('is_active')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->integer('login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->string('invite_token', 100)->nullable();
            $table->timestamp('invite_expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('email', 'uk_email');
            $table->index('scope', 'idx_scope');
            $table->index('is_active', 'idx_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operators');
    }
};
