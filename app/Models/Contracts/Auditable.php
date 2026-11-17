<?php

namespace App\Models\Contracts;

/**
 * A model whose updates and deletes AuditObserver records (T2-8).
 */
interface Auditable
{
    /**
     * A short name for the audit log, e.g. "Classic Milk Tea" or "Toppings › Pearls".
     * Stored with each entry, so it must not lazy-load (strict mode).
     */
    public function auditLabel(): string;
}
