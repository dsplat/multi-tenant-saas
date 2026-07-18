<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_applications', function (Blueprint $table) {
            $table->bigInteger('application_id')->unsigned()->primary();
            $table->bigInteger('operator_id')->unsigned();
            $table->string('code', 30)->unique();
            $table->string('org_name', 255);
            $table->string('org_industry', 100)->nullable();
            $table->string('org_size', 50)->nullable();
            $table->json('contact_info')->nullable();
            $table->string('status', 20)->default('submitted');
            $table->text('review_notes')->nullable();
            $table->bigInteger('reviewed_by')->unsigned()->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('operator_id', 'idx_operator');
            $table->index('status', 'idx_status');
            $table->index('created_at', 'idx_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_applications');
    }
};
