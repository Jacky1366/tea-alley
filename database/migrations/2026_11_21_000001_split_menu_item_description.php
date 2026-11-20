<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #64: bilingual drink descriptions, named like name_en / name_zh.
 *
 * Two Schema::table() calls on purpose: in a single blueprint Laravel runs the "add column"
 * before the rename, so after('description_en') would point at a column that doesn't exist
 * yet on MySQL. The rename keeps the type (text), nullability and any English text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->renameColumn('description', 'description_en');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->text('description_zh')->nullable()->after('description_en');
        });
    }

    /**
     * Rolling back keeps the English text (renamed back to `description`) and drops the Chinese text.
     */
    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn('description_zh');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->renameColumn('description_en', 'description');
        });
    }
};
