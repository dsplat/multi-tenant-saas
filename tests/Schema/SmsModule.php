<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 短信模块
 * 表: sms_templates, sms_batch_tasks, sms_delivery_stats, sms_unsubscribes
 */
class SmsModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('sms_template_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 128);
            $table->text('content');
            $table->json('variables')->nullable();
            $table->string('channel', 20)->default('marketing');
            $table->string('provider_template_id', 128)->nullable();
            $table->string('status', 20)->default('pending_approval');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'channel']);
        });

        Schema::create('sms_batch_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('batch_task_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('sms_template_id');
            $table->string('type', 20)->default('batch_send');
            $table->string('target_type', 20)->default('user_list');
            $table->json('target_ids')->nullable();
            $table->string('phone_column', 50)->default('phone');
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('fail_count')->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_log')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'sms_template_id']);
            $table->index('scheduled_at');
        });

        Schema::create('sms_delivery_stats', function (Blueprint $table) {
            $table->unsignedBigInteger('stat_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('sms_batch_task_id');
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('clicked_count')->default(0);
            $table->unsignedInteger('unsubscribed_count')->default(0);
            $table->decimal('delivery_rate', 5, 2)->default(0);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['tenant_id', 'sms_batch_task_id']);
            $table->index('recorded_at');
        });

        Schema::create('sms_unsubscribes', function (Blueprint $table) {
            $table->unsignedBigInteger('unsubscribe_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('phone', 20);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('reason', 512)->nullable();
            $table->timestamp('unsubscribed_at');
            $table->timestamps();

            $table->index(['tenant_id', 'phone']);
            $table->unique(['tenant_id', 'phone'], 'sms_unsubscribes_tenant_phone_unique');
        });
    }

    public function getTableNames(): array
    {
        return ['sms_templates', 'sms_batch_tasks', 'sms_delivery_stats', 'sms_unsubscribes'];
    }
}
