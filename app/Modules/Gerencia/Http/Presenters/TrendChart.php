<?php

namespace App\Modules\Gerencia\Http\Presenters;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Gerencia\Application\Portfolio\ScopeFigures;
use App\Modules\Gerencia\Domain\Portfolio\MonthPoint;
use Carbon\CarbonImmutable;

/**
 * The overdue trend as the line chart and its table need it: one series
 * per scope over the months any scope has a snapshot for, so every series
 * lines up on the same horizontal positions.
 *
 * @phpstan-import-type Series from LineChartGeometry
 */
final class TrendChart
{
    /**
     * @param  list<ScopeFigures>  $scopes
     * @return array{
     *     series: list<Series>,
     *     rows: list<array{month: string, total: float|null, overdue: float|null, percents: list<float|null>}>
     * }
     */
    public static function from(array $scopes): array
    {
        /** @var array<string, CarbonImmutable> $months */
        $months = [];

        foreach ($scopes as $scope) {
            foreach ($scope->trend as $point) {
                $months[$point->month->format('Y-m')] = $point->month;
            }
        }

        ksort($months);

        $series = [];
        $percents = [];

        foreach ($scopes as $scope) {
            $byMonth = [];

            foreach ($scope->trend as $point) {
                $byMonth[$point->month->format('Y-m')] = $point;
            }

            $points = [];
            $values = [];

            foreach ($months as $key => $month) {
                $value = isset($byMonth[$key]) ? $byMonth[$key]->overduePercent() : null;
                $values[] = $value;
                $points[] = [
                    'label' => Figures::monthShort($month),
                    'title' => Figures::monthLong($month),
                    'value' => $value,
                    'text' => $value === null ? __('gerencia::tablero.grafico.sin_dato') : Figures::percent($value),
                ];
            }

            $series[] = ['name' => $scope->name, 'tone' => self::tone($scope->company), 'points' => $points];
            $percents[] = $values;
        }

        $rows = [];
        $first = $scopes[0] ?? null;
        $firstByMonth = [];

        foreach ($first->trend ?? [] as $point) {
            $firstByMonth[$point->month->format('Y-m')] = $point;
        }

        $index = 0;

        foreach ($months as $key => $month) {
            /** @var MonthPoint|null $point */
            $point = $firstByMonth[$key] ?? null;

            $rows[] = [
                'month' => Figures::monthLong($month),
                'total' => $point?->total,
                'overdue' => $point?->overdue,
                'percents' => array_map(fn (array $values): ?float => $values[$index], $percents),
            ];
            $index++;
        }

        return ['series' => $series, 'rows' => $rows];
    }

    /**
     * The color of a scope follows the entity, never its position.
     */
    public static function tone(?CompanyCode $company): string
    {
        return match ($company) {
            CompanyCode::SGX => 'sgx',
            CompanyCode::SDC => 'sdc',
            default => 'group',
        };
    }
}
