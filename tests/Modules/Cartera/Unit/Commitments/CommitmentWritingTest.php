<?php

use App\Modules\Cartera\Domain\Commitments\CommitmentWriting;
use App\Modules\Core\Contracts\Enums\CompanyCode;

it('lets the collector write commitments only in SDC', function (CompanyCode $company, bool $collectorWrites) {
    expect(CommitmentWriting::byCollector($company))->toBe($collectorWrites);
})->with([
    'SDC has a single advisor, so the analyst writes' => [CompanyCode::SDC, true],
    'in SGX the advisor of the client writes' => [CompanyCode::SGX, false],
]);
