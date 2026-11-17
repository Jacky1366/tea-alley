<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T2-8: who changed or deleted a drink or a modifier option, when, and from what to what.
     * Written by AuditObserver, read by the admin-only AuditLogResource. Append-only.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Null for changes made outside a request (console, seeders). A deleted user's
            // entries stay, without the user.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 10); // updated, deleted
            $table->morphs('auditable');
            // The record's name when the entry was written, so a deleted record is still recognisable.
            $table->string('auditable_label');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
