<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name_en');
            $table->string('name_zh');
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents');
            $table->string('photo_path')->nullable();
            $table->string('tax_category')->default('standard'); // standard, carbonated
            $table->unsignedTinyInteger('prep_minutes')->default(3);
            $table->boolean('is_available')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
