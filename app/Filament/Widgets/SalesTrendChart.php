<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ForMenuManagers;
use App\Services\SalesReport;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/**
 * Sales per Vancouver day for the last 7 days, today last.
 */
class SalesTrendChart extends ChartWidget
{
    use ForMenuManagers;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Sales, last 7 days';

    protected ?string $description = 'Before tax, by the day each order was paid (Vancouver time).';

    protected ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $days = app(SalesReport::class)->dailySales(7);

        return [
            'datasets' => [[
                'label' => 'Sales ($)',
                // Display only: dollars for the chart's axis. The cents come from stored order totals.
                'data' => array_values(array_map(fn (int $cents): float => $cents / 100, $days)),
            ]],
            'labels' => array_map(fn (string $day): string => CarbonImmutable::parse($day)->format('D M j'), array_keys($days)),
        ];
    }
}
