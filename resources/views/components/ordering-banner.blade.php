@switch($state)
    @case('open')
        <flux:callout icon="clock" color="green" data-test="ordering-open">
            <flux:callout.text>
                {{ __('Online ordering is open until :time', ['time' => $at ? \App\Support\LocalTime::format($at, 'time') : '']) }}
            </flux:callout.text>
        </flux:callout>
        @break
    @case('closed')
        <flux:callout icon="clock" color="amber" data-test="ordering-closed">
            <flux:callout.text>
                {{ __('Online ordering opens :time', ['time' => $at ? \App\Support\LocalTime::format($at, 'day_time') : '']) }}
            </flux:callout.text>
        </flux:callout>
        @break
    @default
        <flux:callout icon="clock" color="zinc" data-test="ordering-unavailable">
            <flux:callout.text>{{ __('Online ordering is currently unavailable') }}</flux:callout.text>
        </flux:callout>
@endswitch
