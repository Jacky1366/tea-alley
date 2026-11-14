<?php

use App\Enums\OrderStatus;

/**
 * The six legal moves from issue #27, written out here rather than read from
 * OrderStatus::canTransitionTo(), so the tests check the table instead of repeating it.
 *
 * @return list<string> "from>to"
 */
function legalOrderTransitions(): array
{
    return [
        'pending_payment>paid',
        'pending_payment>cancelled',
        'paid>preparing',
        'preparing>ready',
        'ready>preparing',
        'ready>picked_up',
    ];
}

function isLegalOrderTransition(OrderStatus $from, OrderStatus $to): bool
{
    return in_array("{$from->value}>{$to->value}", legalOrderTransitions(), true);
}

// All 36 (from, to) pairs, including moving to the same status.
dataset('every status pair', function () {
    foreach (OrderStatus::cases() as $from) {
        foreach (OrderStatus::cases() as $to) {
            yield "{$from->value} → {$to->value}" => [$from, $to];
        }
    }
});
