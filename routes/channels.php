<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Private channels. /broadcasting/auth runs in the web middleware group, so it uses the
 * same session as the rest of the app; guests are refused before these callbacks run.
 * The callbacks reuse the same checks as the pages they feed.
 */

// The barista board: the same roles as the /barista route (User::canUseBaristaBoard()).
Broadcast::channel('barista', fn (User $user): bool => $user->canUseBaristaBoard());

// One order's status page: only its owner (OrderPolicy::view, like the /orders/{number} route).
Broadcast::channel('orders.{order}', fn (User $user, Order $order): bool => $user->can('view', $order));
