<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('ticket_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('subject', 255);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('open'); // open, in_progress, resolved, closed
            $table->string('priority', 20)->default('medium'); // low, medium, high, urgent
            $table->string('category', 50)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('status');
            $table->index('assigned_to');
        });

        Schema::create('ticket_comments', function (Blueprint $table) {
            $table->unsignedBigInteger('comment_id')->primary();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('user_id');
            $table->text('content');
            $table->timestamps();

            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_comments');
        Schema::dropIfExists('tickets');
    }
};
