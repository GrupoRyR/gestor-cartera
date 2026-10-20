<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Contracts\Data\MailRunResults;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\MailRunKind;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Results of the collection-mail runs (weekly collection and
 * circularization): clients mailed, bounced, without e-mail, whose send
 * failed and who answered, per company, kind and period.
 *
 * Implemented by Cobro (phase 2); tests use
 * Tests\Fakes\FakeMailRunResultsProvider.
 *
 * Not user-scoped: callers authorize, e.g. the management dashboard. A run
 * belongs to the period when its scheduled date, as a calendar day in
 * America/Bogota, is inside the range; both ends are inclusive and only
 * the date of $from and $to counts. Test sends are never runs, so they
 * never count. Replies count whenever they arrive, also after the period.
 */
interface MailRunResultsProvider
{
    /**
     * The runs of the company and kind scheduled in the period, added up;
     * every count is zero when there is none.
     *
     * @throws InvalidArgumentException When $from is after $to.
     */
    public function results(CompanyCode $company, MailRunKind $kind, CarbonImmutable $from, CarbonImmutable $to): MailRunResults;
}
