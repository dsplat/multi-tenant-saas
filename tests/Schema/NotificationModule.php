<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 通知模块
 * 表: notifications, notification_preferences, in_app_notifications, mail_templates
 */
class NotificationModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index('read_at');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->unsignedBigInteger('notification_preference_id')->primary();
            $table->bigInteger('user_id')->unsigned();
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
            $table->string('channel', 30);
            $table->string('type', 100)->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('options')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'channel', 'type'], 'notif_pref_unique');
        });

        Schema::create('in_app_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('in_app_notification_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('type', 30)->default('system');
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('user_id');
            $table->index(['tenant_id', 'user_id', 'is_read'], 'idx_tenant_user_read');
            $table->index(['tenant_id', 'user_id', 'type'], 'idx_tenant_user_type');
            $table->index(['user_id', 'is_read'], 'idx_user_read');
        });

        Schema::create('mail_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('type', 50);
            $table->string('name_key', 50)->nullable();
            $table->string('name');
            $table->string('subject');
            $table->longText('html_body');
            $table->text('text_body')->nullable();
            $table->json('variables')->nullable();
            $table->string('status', 20)->default('activated');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type']);
            $table->index(['type', 'status']);
            $table->index(['name_key', 'tenant_id']);

            $table->foreign('tenant_id')
                ->references('tenant_id')
                ->on('tenants')
                ->onDelete('cascade');
        });
    }

    public function getTableNames(): array
    {
        return ['notifications', 'notification_preferences', 'in_app_notifications', 'mail_templates'];
    }
}
