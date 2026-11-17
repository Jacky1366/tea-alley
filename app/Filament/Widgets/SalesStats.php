<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ForMenuManagers;
use App\Services\SalesReport;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Today's sales (Vancouver date), and the last 7 and 30 days. See SalesReport for what counts.
 */
class SalesStats extends StatsOverviewWidget
{
    use ForMenuManagers;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $report = app(SalesReport::class);

        return [
            $this->stat('Sales today', $report->totals(SalesReport::dayStart())),
            $this->stat('Last 7 days', $report->totals(SalesReport::dayStart(6))),
            $this->stat('Last 30 days', $report->totals(SalesReport::recentStart())),
        ];
    }

    /**
     * @param  array{orders: int, sales_cents: int, collected_cents: int}  $totals
     */
    private function stat(string $label, array $totals): Stat
    {
        return Stat::make($label, Money::format($totals['sales_cents']))
            ->description(sprintf(
                '%d %s · %s collected with tax',
                $totals['orders'],
                $totals['orders'] === 1 ? 'order' : 'orders',
                Money::format($totals['collected_cents']),
            ));
    }
}
