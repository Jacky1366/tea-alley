<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // When the current Stripe Checkout Session expires. A retry with a new Session moves
            // it; orders:cancel-stale only cancels a pending order once this is well past.
            $table->timestamp('payment_expires_at')->nullable()->after('stripe_session_id');

            $table->index(['status', 'payment_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'payment_expires_at']);
            $table->dropColumn('payment_expires_at');
        });
    }
};
