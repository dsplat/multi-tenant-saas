<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->bigInteger('tenant_id')->unsigned()->primary();
            $table->string('name', 100);
            $table->string('slug', 100)->nullable()->unique();
            $table->string('domain', 200)->nullable();
            $table->string('custom_domain', 200)->nullable()->unique();
            $table->string('logo', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('subscription_plan', 50)->default('free');
            $table->timestamp('subscription_started_at')->nullable();
            $table->timestamp('subscription_expires_at')->nullable();
            $table->integer('total_credits')->default(0);
            $table->integer('used_credits')->default(0);
            $table->string('contact_name', 50)->nullable();
            $table->string('contact_email', 100)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->json('settings')->nullable();
            $table->json('branding')->nullable();
            $table->boolean('is_platform_default')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamp('ssl_uploaded_at')->nullable();
            $table->timestamp('ssl_cert_expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('subscription_plan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
