<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('is_template')->default(false)->after('metadata')->comment('是否为模板');
            $table->unsignedBigInteger('template_id')->nullable()->after('is_template')->comment('关联模板ID');
            $table->foreign('template_id')->references('coupon_id')->on('coupons')->onDelete('set null');
            $table->index('is_template');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->dropIndex(['is_template']);
            $table->dropColumn(['is_template', 'template_id']);
        });
    }
};
