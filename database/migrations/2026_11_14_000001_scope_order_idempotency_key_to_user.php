<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency keys only need to be unique per user. With a global unique index, a
 * user who submits another user's key would collide with that user's order instead
 * of getting their own (OrderService always looks orders up by user_id and key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->unique(['user_id', 'idempotency_key']);
        });

        // Left by down() after a rollback; the composite index already covers user_id.
        if (Schema::hasIndex('orders', 'orders_user_id_index')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropIndex(['user_id']));
        }
    }

    /**
     * Fails if two users already share a key.
     *
     * MySQL dropped its automatic user_id foreign-key index when the composite index
     * (which starts with user_id) was added, so the foreign key now depends on the
     * composite index. Give it its own index again before dropping the composite one.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('user_id');
            $table->dropUnique(['user_id', 'idempotency_key']);
            $table->unique('idempotency_key');
        });
    }
};
