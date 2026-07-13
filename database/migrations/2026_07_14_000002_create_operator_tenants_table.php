<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_tenants', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('operator_id')->unsigned();
            $table->bigInteger('tenant_id')->unsigned();
            $table->bigInteger('user_id')->unsigned();
            $table->string('role', 50);
            $table->bigInteger('role_id')->unsigned()->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['operator_id', 'tenant_id']);
            $table->foreign('operator_id')->references('operator_id')->on('operators')->onDelete('cascade');
            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
            $table->index('tenant_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_tenants');
    }
};
