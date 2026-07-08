<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lottery_prizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained('lottery_pools')->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->integer('quantity');
            $table->decimal('probability', 5, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottery_prizes');
    }
};
