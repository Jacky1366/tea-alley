<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ForMenuManagers;
use App\Services\SalesReport;
use Filament\Widgets\ChartWidget;

/**
 * The 5 most sold drinks in the last 30 days, under the name they were ordered as.
 */
class BestSellersChart extends ChartWidget
{
    use ForMenuManagers;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Best sellers, last 30 days';

    protected ?string $description = 'Drinks sold.';

    protected ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $sellers = app(SalesReport::class)->bestSellers(SalesReport::recentStart());

        return [
            'datasets' => [['label' => 'Drinks sold', 'data' => array_values($sellers)]],
            'labels' => array_keys($sellers),
        ];
    }

    protected function getOptions(): array
    {
        return ['indexAxis' => 'y', 'plugins' => ['legend' => ['display' => false]]];
    }
}
