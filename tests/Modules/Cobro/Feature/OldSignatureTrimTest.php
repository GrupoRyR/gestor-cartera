<?php

use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Infrastructure\Persistence\Template;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\ContractFakes;

/*
 * Every Cobro mail now ends with the company's signature (cobro::correos.firma),
 * so the migration 2026_10_05_000009 cuts the old written signature off the
 * saved templates, only when it is exactly one of the default ones; an
 * edited signature stays, with a warning, for someone to remove by hand.
 */

beforeEach(function () {
    $this->fakes = ContractFakes::install($this->app);
});

function cobroRunSignatureTrim(): void
{
    (require app_path('Modules/Cobro/database/migrations/2026_10_05_000009_trim_old_signature_from_cobro_templates.php'))->up();
}

function cobroSavedTemplate(CompanyCode $company, RunKind $kind, string $closing): Template
{
    return Template::query()->create([
        'company' => $company,
        'kind' => $kind,
        'subject' => 'Cobro de cartera',
        'intro' => 'Estimado cliente {cliente}',
        'closing' => $closing,
    ]);
}

it('cuts each default written signature off a saved template, keeping the rest', function (CompanyCode $company, RunKind $kind, string $signature) {
    $template = cobroSavedTemplate($company, $kind, "Texto que alguien editó.\n\nAgradecemos su atención.\n\n".$signature);

    cobroRunSignatureTrim();

    expect($template->refresh()->closing)->toBe("Texto que alguien editó.\n\nAgradecemos su atención.");
    $this->fakes->audit->assertNothingRecorded();
    $this->fakes->mail->assertNothingSent();
})->with([
    'weekly, Segurex' => [CompanyCode::SGX, RunKind::Weekly, "**Cordialmente,**\n\n**María Paula Alvarez**\n**Auxiliar de Cartera**"],
    'weekly, Derivados' => [CompanyCode::SDC, RunKind::Weekly, "Cordialmente,\n\n**María Paula Alvarez**\n**Auxiliar de Crédito y Cobranza**\nCelular: +57 3153654788\nE-mail: cartera@derivadoscol.com"],
    'circularization, Segurex' => [CompanyCode::SGX, RunKind::Circularization, "Cordialmente,\n\n**María Paula Alvarez**\nAuxiliar de Cartera"],
    'circularization, Derivados' => [CompanyCode::SDC, RunKind::Circularization, "**Cordialmente,**\n\n**María Paula Alvarez**\n**Auxiliar de Crédito y Cobranza**\n**Celular: +57 3153654788**\n**E-mail: cartera@derivadoscol.com**"],
]);

it('matches the signature as the editor saved it, with Windows line breaks and trailing spaces', function () {
    $template = cobroSavedTemplate(CompanyCode::SGX, RunKind::Weekly, "Agradecemos su pronta atención a este asunto.\r\n\r\n**Cordialmente,**\r\n\r\n**María Paula Alvarez**\r\n**Auxiliar de Cartera**  \r\n");

    cobroRunSignatureTrim();

    expect($template->refresh()->closing)->toBe('Agradecemos su pronta atención a este asunto.');
});

it('keeps an edited signature and warns which template still has it', function () {
    Log::spy();
    $closing = "Agradecemos su atención.\n\nCordialmente,\n\n**Laura Gómez**\nAnalista de Cartera";
    $template = cobroSavedTemplate(CompanyCode::SDC, RunKind::Weekly, $closing);

    cobroRunSignatureTrim();

    expect($template->refresh()->closing)->toBe($closing);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'written signature')
            && $context === ['company' => 'SDC', 'kind' => 'cobro_semanal'])
        ->once();
});

it('leaves a template without a written signature as it is, without warnings', function () {
    Log::spy();
    $template = cobroSavedTemplate(CompanyCode::SGX, RunKind::Weekly, 'Agradecemos su pronta atención a este asunto.');

    cobroRunSignatureTrim();

    expect($template->refresh()->closing)->toBe('Agradecemos su pronta atención a este asunto.');
    Log::shouldNotHaveReceived('warning');
});
