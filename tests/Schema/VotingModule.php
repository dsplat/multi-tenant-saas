<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 投票模块
 * 表: votes, vote_options, vote_records
 */
class VotingModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('votes', function (Blueprint $table) {
            $table->unsignedBigInteger('vote_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('vote_type', 20)->default('single');
            $table->string('status', 20)->default('draft');
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->unsignedInteger('daily_limit')->default(0);
            $table->unsignedInteger('total_limit')->default(0);
            $table->unsignedInteger('daily_limit_per_user')->default(1);
            $table->unsignedInteger('total_limit_per_user')->default(0);
            $table->boolean('anti_cheat_ip')->default(true);
            $table->boolean('show_result')->default(true);
            $table->boolean('show_rank')->default(true);
            $table->unsignedInteger('total_votes')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('vote_options', function (Blueprint $table) {
            $table->unsignedBigInteger('vote_option_id')->primary();
            $table->unsignedBigInteger('vote_id');
            $table->string('title', 255);
            $table->string('image', 512)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('vote_count')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['vote_id', 'sort_order']);
        });

        Schema::create('vote_records', function (Blueprint $table) {
            $table->unsignedBigInteger('vote_record_id')->primary();
            $table->unsignedBigInteger('vote_id');
            $table->unsignedBigInteger('vote_option_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index(['vote_id', 'user_id']);
            $table->index(['vote_id', 'vote_option_id']);
            $table->index(['user_id', 'vote_id']);
        });
    }

    public function getTableNames(): array
    {
        return ['votes', 'vote_options', 'vote_records'];
    }
}
