<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_shares', function (Blueprint $table) {
            $table->unsignedBigInteger('coupon_share_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('sharer_id');
            $table->unsignedBigInteger('receiver_id')->nullable();
            $table->unsignedBigInteger('coupon_template_id');
            $table->string('share_code', 64)->unique();
            $table->string('status', 20)->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'sharer_id']);
            $table->index('share_code');
            $table->foreign('coupon_template_id')->references('coupon_id')->on('coupons')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_shares');
    }
};
