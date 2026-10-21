<?php

use App\Modules\Cobro\Domain\Runs\NoticeData;
use App\Modules\Cobro\Domain\Templates\Placeholders;
use App\Modules\Cobro\Infrastructure\Mail\NoticeRenderer;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Tests\Fakes\Make;

/*
 * {fecha_cierre}: the cutoff date of the cartera in Spanish words, as the
 * circularization subject and text write it.
 */

function cobroCutoffWordsValues(?string $cutoff): array
{
    return NoticeRenderer::values(
        Make::company(code: CompanyCode::SGX),
        new NoticeData('Cliente', '900', [], $cutoff === null ? null : CarbonImmutable::parse($cutoff, 'America/Bogota')),
    );
}

it('writes the cutoff date in words, with the month in lowercase', function (string $cutoff, string $words) {
    expect(cobroCutoffWordsValues($cutoff)['fecha_cierre'])->toBe($words);
})->with([
    'end of May' => ['2026-05-31', '31 de mayo de 2026'],
    'first day of the year' => ['2027-01-01', '1 de enero de 2027'],
    'end of the year' => ['2026-12-31', '31 de diciembre de 2026'],
]);

it('keeps {fecha_corte} as a DD/MM/AAAA date', function () {
    expect(cobroCutoffWordsValues('2026-05-31')['fecha_corte'])->toBe('31/05/2026');
});

it('writes a dash without a cutoff date', function () {
    expect(cobroCutoffWordsValues(null)['fecha_cierre'])->toBe('—');
});

it('accepts {fecha_cierre} in a template and describes it', function () {
    expect(Placeholders::unknown('Corte al {fecha_cierre}'))->toBe([])
        ->and(Placeholders::ALLOWED['fecha_cierre'])->toBe('Fecha de corte de la cartera en letras, p. ej. 31 de mayo de 2026');
});
