<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lottery_pools', function (Blueprint $table) {
            $table->id();
            $table->json('prize_config')->nullable();
            $table->json('probability_rules')->nullable();
            $table->json('anti_abuse_config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottery_pools');
    }
};
