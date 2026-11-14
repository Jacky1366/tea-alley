<?php

use App\Enums\OrderStatus;

test('the state machine allows exactly the six moves in the issue', function (OrderStatus $from, OrderStatus $to) {
    expect($from->canTransitionTo($to))->toBe(isLegalOrderTransition($from, $to));
})->with('every status pair');

test('there are 36 pairs, 6 of them legal', function () {
    $pairs = collect(OrderStatus::cases())->crossJoin(OrderStatus::cases());

    expect($pairs)->toHaveCount(36)
        ->and($pairs->filter(fn (array $pair) => $pair[0]->canTransitionTo($pair[1])))->toHaveCount(6);
});

test('picked up and cancelled are final', function (OrderStatus $final) {
    foreach (OrderStatus::cases() as $to) {
        expect($final->canTransitionTo($to))->toBeFalse();
    }
})->with([OrderStatus::PickedUp, OrderStatus::Cancelled]);

test('no status can move to itself', function (OrderStatus $status) {
    // Order::transitionTo relies on this: on MySQL an UPDATE that changes nothing reports 0 rows.
    expect($status->canTransitionTo($status))->toBeFalse();
})->with(OrderStatus::cases());

test('isPaid covers paid and every later step of making the order', function (OrderStatus $status, bool $paid) {
    expect($status->isPaid())->toBe($paid);
})->with([
    [OrderStatus::PendingPayment, false],
    [OrderStatus::Paid, true],
    [OrderStatus::Preparing, true],
    [OrderStatus::Ready, true],
    [OrderStatus::PickedUp, true],
    [OrderStatus::Cancelled, false],
]);
