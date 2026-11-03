<?php

use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Tests\Fakes\Make;

/*
 * <x-cobro::correo>, the frame of the Cobro mails: brand v2 colors of the
 * company that sends the mail, the Helvetica stack and a 600 px column,
 * without images (the logo is separate work).
 */

function cobroFramedMail(CompanyCode $company, bool $framed = true): string
{
    return (string) test()->blade(
        '<x-cobro::correo :company="$company" title="Aviso" :framed="$framed">Hola</x-cobro::correo>',
        ['company' => Make::company(code: $company), 'framed' => $framed],
    );
}

it('frames the mail in a 600 px column under the company header band', function (CompanyCode $company) {
    $html = cobroFramedMail($company);
    $palette = BrandPalette::official($company);

    expect($html)->toContain('data-marco="empresa"')
        ->toContain('max-width:600px;')
        ->toMatch('/<body style="[^"]*font-family:Helvetica,Arial,\'Helvetica Neue\',sans-serif;[^"]*color:'.$palette->text.';/')
        // White on the header: 7.01:1 on the Segurex gray, 11.50:1 on the SDC blue.
        ->toContain('background:'.$palette->head.';border-bottom:4px solid '.$palette->accent.';color:#ffffff;')
        ->not->toContain('<img');
})->with([CompanyCode::SGX, CompanyCode::SDC]);

it('drops the band and the column for a mail in the company format, keeping the font and text color', function () {
    $html = cobroFramedMail(CompanyCode::SDC, framed: false);

    expect($html)->not->toContain('data-marco="empresa"')
        ->toMatch('/<body style="[^"]*font-family:Helvetica,Arial,\'Helvetica Neue\',sans-serif;[^"]*color:'.BrandPalette::official(CompanyCode::SDC)->text.';/');
});
