<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('name', 100);
            $table->string('metric', 100);
            $table->string('operator', 10)->default('>');
            $table->double('threshold')->default(0);
            $table->string('severity', 20)->default('warning');
            $table->json('channels')->nullable();
            $table->integer('cooldown_sec')->default(300);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled']);
            $table->index('metric');
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('rule_name', 100);
            $table->string('severity', 20);
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamps();

            $table->index(['tenant_id', 'triggered_at']);
            $table->index(['rule_name', 'triggered_at']);
            $table->index('severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('alert_rules');
    }
};
