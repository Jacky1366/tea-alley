<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ForMenuManagers;
use App\Services\SalesReport;
use Filament\Widgets\ChartWidget;

/**
 * How sweet customers take their drinks: each sugar option's share of the drinks sold in
 * the last 30 days.
 */
class SugarLevelsChart extends ChartWidget
{
    use ForMenuManagers;

    protected static ?int $sort = 4;

    protected ?string $heading = 'Sugar levels, last 30 days';

    protected ?string $description = 'Drinks with each sugar level.';

    protected ?string $maxHeight = '260px';

    private const COLORS = ['#f59e0b', '#fbbf24', '#fcd34d', '#fde68a', '#d97706', '#92400e', '#a8a29e'];

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $levels = app(SalesReport::class)->optionCounts(SalesReport::SUGAR_GROUP, SalesReport::recentStart());

        return [
            'datasets' => [[
                'label' => 'Drinks',
                'data' => array_values($levels),
                'backgroundColor' => array_slice(array_pad(self::COLORS, count($levels), '#a8a29e'), 0, count($levels)),
            ]],
            'labels' => array_keys($levels),
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['x' => ['display' => false], 'y' => ['display' => false]]];
    }
}
