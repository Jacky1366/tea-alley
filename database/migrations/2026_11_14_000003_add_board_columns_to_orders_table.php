<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Snapshot: the longest prep_minutes among the order's drinks, for the board's red threshold.
            $table->unsignedTinyInteger('prep_minutes')->default(0)->after('pickup_at');
            // How many times staff sent the order back from ready to preparing.
            $table->unsignedSmallInteger('recalled_count')->default(0)->after('cancelled_at');

            // The barista board: today's paid / preparing / ready orders by pickup time.
            $table->index(['status', 'pickup_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'pickup_at']);
            $table->dropColumn(['prep_minutes', 'recalled_count']);
        });
    }
};
