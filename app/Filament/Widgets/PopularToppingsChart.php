<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ForMenuManagers;
use App\Services\SalesReport;
use Filament\Widgets\ChartWidget;

/**
 * Toppings by the number of drinks sold with them in the last 30 days.
 */
class PopularToppingsChart extends ChartWidget
{
    use ForMenuManagers;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Popular toppings, last 30 days';

    protected ?string $description = 'Drinks with each topping.';

    protected ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $toppings = app(SalesReport::class)->optionCounts(SalesReport::TOPPINGS_GROUP, SalesReport::recentStart());

        return [
            'datasets' => [['label' => 'Drinks', 'data' => array_values($toppings)]],
            'labels' => array_keys($toppings),
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['display' => false]]];
    }
}
