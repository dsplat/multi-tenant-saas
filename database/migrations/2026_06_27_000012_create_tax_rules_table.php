<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->index();
            $table->string('region_code', 10);
            $table->decimal('tax_rate', 5, 4);
            $table->string('tax_name');
            $table->date('effective_date');
            $table->date('expiry_date')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('region_code');
            $table->index(['region_code', 'is_default']);
            $table->index('effective_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rules');
    }
};
