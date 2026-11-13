<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('day_of_week')->unique(); // 0 = Sunday ... 6 = Saturday (Carbon)
            // Deliberate exception to the "store times in UTC" rule: these are
            // America/Vancouver wall-clock times. A UTC time would shift by an
            // hour whenever DST starts or ends.
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->unsignedSmallInteger('ordering_cutoff_minutes')->default(15);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_hours');
    }
};
