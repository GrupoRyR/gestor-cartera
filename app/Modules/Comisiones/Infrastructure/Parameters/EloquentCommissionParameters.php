<?php

namespace App\Modules\Comisiones\Infrastructure\Parameters;

use App\Modules\Comisiones\Application\Ports\CommissionParameters;
use App\Modules\Comisiones\Domain\Parameters\ParameterBook;
use App\Modules\Comisiones\Infrastructure\Persistence\ParameterRecord;

/**
 * The parameter book stored in comisiones_parametros, in insertion order so
 * teams and derived commissions keep the order they were first defined in.
 */
final class EloquentCommissionParameters implements CommissionParameters
{
    public function __construct(
        private readonly ParameterMapper $mapper,
    ) {}

    public function book(): ParameterBook
    {
        return $this->mapper->book(
            ParameterRecord::query()->orderBy('id')->get()
                ->map(fn (ParameterRecord $row): array => $row->toMapperRecord())
                ->all(),
        );
    }
}
