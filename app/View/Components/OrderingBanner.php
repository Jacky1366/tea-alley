<?php

namespace App\View\Components;

use App\Services\OrderingWindow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Online ordering status banner, shared by the menu and the drink builder.
 * Times are in the display timezone.
 */
class OrderingBanner extends Component
{
    /** @var 'open'|'closed'|'unavailable' */
    public string $state;

    public ?CarbonImmutable $at;

    public function __construct(OrderingWindow $window)
    {
        $now = now();

        if (($endsAt = $window->orderingEndsAt($now)) !== null) {
            [$this->state, $this->at] = ['open', $endsAt];
        } elseif (($opensAt = $window->nextOpening($now)) !== null) {
            [$this->state, $this->at] = ['closed', $opensAt];
        } else {
            [$this->state, $this->at] = ['unavailable', null];
        }
    }

    public function render(): View
    {
        return view('components.ordering-banner');
    }
}
