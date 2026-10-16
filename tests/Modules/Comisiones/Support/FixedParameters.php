<?php

namespace Tests\Modules\Comisiones\Support;

use App\Modules\Comisiones\Application\DefaultParameters;
use App\Modules\Comisiones\Application\Ports\CommissionParameters;
use App\Modules\Comisiones\Domain\Parameters\ParameterBook;

/**
 * The parameter book a test works with: the default one (Annex 1) unless
 * the test gives or later changes it.
 */
final class FixedParameters implements CommissionParameters
{
    private ParameterBook $book;

    public function __construct(?ParameterBook $book = null)
    {
        $this->book = $book ?? DefaultParameters::book();
    }

    /** Replaces the book, as a parameter administrator would. */
    public function change(ParameterBook $book): void
    {
        $this->book = $book;
    }

    public function book(): ParameterBook
    {
        return $this->book;
    }
}
