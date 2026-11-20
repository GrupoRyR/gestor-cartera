<?php

use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Domain\Runs\RenderedMail;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Domain\Signatures\SignaturePerson;
use App\Modules\Cobro\Infrastructure\Runs\RunDefinitions;
use App\Modules\Cobro\Infrastructure\Signatures\SignatureStore;
use App\Modules\Cobro\Infrastructure\Templates\TemplateStore;
use App\Modules\Core\Contracts\Data\MailAttachment;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Modules\Cobro\Support\CobroPortfolio;
use Tests\Modules\Cobro\Support\CobroUsers;
use Tests\Modules\Cobro\Support\VisibleText;

/*
 * The weekly collection and the circularization end with the cartera
 * signature of their company: the person of /cobro/firma, the fixed
 * company data and its PNG images, attached inline (cid:) so Outlook and
 * Gmail show them. The browser previews show the same images as data: URIs.
 */

const COBRO_SGX_SIGNATURE = 'Cordialmente, María Paula Alvarez Auxiliar de Cartera SEGUREX LATAM S.A.S. Calle 25D No. 95-45 Bogotá, D.C. - Colombia '
    .'Móvil: +3153654788 PBX: +57 (60-1) 3713766 Ext. 141 cartera@segurex.com www.segurex.com '
    .'Antes de imprimir, piense en su responsabilidad con el Medio Ambiente. Aviso legal: Este mensaje es solamente para la persona a la que va dirigido.';

const COBRO_SDC_SIGNATURE = 'Cordialmente, María Paula Alvarez Auxiliar de Cartera Móvil: +3153654788 PBX: +57 (60-1) 3713766 Ext. 141 '
    .'cartera@derivadoscol.com SOLUCIONES Y DERIVADOS DE COLOMBIA S.A.S. Km. 5 Vía Cajicá – Zipaquirá, Código Postal 250247 Cajicá, Colombia';

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-12-07 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->portfolio = CobroPortfolio::seed($this->fakes);
    $this->fakes->cartera->cutoffDate = CarbonImmutable::parse('2026-11-30', 'America/Bogota');
    CobroPortfolio::closeMonth($this->fakes, 2026, 11);
});

/**
 * The mail of $kind for the client of $company in the shared portfolio.
 */
function cobroSignedMail(RunKind $kind, CompanyCode $company): RenderedMail
{
    $portfolio = test()->portfolio;
    $client = $company === CompanyCode::SGX ? $portfolio->tornillo : $portfolio->quimicos;
    $definition = app(RunDefinitions::class)->for($kind);
    $notice = $definition->notice($client->summary->id, $definition->cutoffFor($company, CarbonImmutable::now()));

    return $definition->render(Make::company(code: $company), $notice);
}

/**
 * The content ids the HTML refers to with cid:, in order.
 *
 * @return list<string>
 */
function cobroCids(string $html): array
{
    preg_match_all('/<img\b[^>]*\bsrc="cid:([^"]+)"/i', $html, $matches);

    return $matches[1];
}

it('signs each mail with the person and the data of its company', function (RunKind $kind, CompanyCode $company, string $signature) {
    $text = VisibleText::of(cobroSignedMail($kind, $company)->html);

    expect($text)->toContain($signature)
        ->and(substr_count($text, 'Cordialmente'))->toBe(1)
        ->and(substr_count($text, 'María Paula Alvarez'))->toBe(1);
})->with([
    'weekly, Segurex' => [RunKind::Weekly, CompanyCode::SGX, COBRO_SGX_SIGNATURE],
    'weekly, Derivados' => [RunKind::Weekly, CompanyCode::SDC, COBRO_SDC_SIGNATURE],
    'circularization, Segurex' => [RunKind::Circularization, CompanyCode::SGX, COBRO_SGX_SIGNATURE],
    'circularization, Derivados' => [RunKind::Circularization, CompanyCode::SDC, COBRO_SDC_SIGNATURE],
]);

it('shows the company images inline, each one attached under its content id', function (RunKind $kind, CompanyCode $company, array $cids) {
    $mail = cobroSignedMail($kind, $company);
    $files = ['sgx-redes' => 'sgx-redes.png', 'sgx-logo' => 'sgx-logo.png', 'sdc' => 'sdc.png'];

    expect(cobroCids($mail->html))->toBe($cids)
        ->and(array_map(fn (MailAttachment $attachment): ?string => $attachment->contentId, $mail->attachments))->toBe($cids);

    foreach ($mail->attachments as $attachment) {
        expect($attachment->isInline())->toBeTrue()
            ->and($attachment->contentType)->toBe('image/png')
            ->and($attachment->filename)->toBe($files[$attachment->contentId])
            ->and($attachment->content)->toBe(file_get_contents(app_path('Modules/Cobro/resources/firmas/'.$files[$attachment->contentId])));
    }

    // Every image has its size and a text for clients that block images.
    preg_match_all('/<img\b[^>]*>/i', $mail->html, $images);

    foreach ($images[0] as $image) {
        expect($image)->toMatch('/\bwidth="\d+"/')->toMatch('/\bheight="\d+"/')->toMatch('/\balt="[^"]+"/');
    }
})->with([
    'weekly, Segurex' => [RunKind::Weekly, CompanyCode::SGX, ['sgx-redes', 'sgx-logo']],
    'weekly, Derivados' => [RunKind::Weekly, CompanyCode::SDC, ['sdc']],
    'circularization, Segurex' => [RunKind::Circularization, CompanyCode::SGX, ['sgx-redes', 'sgx-logo']],
    'circularization, Derivados' => [RunKind::Circularization, CompanyCode::SDC, ['sdc']],
]);

it('signs with the person saved in /cobro/firma, without an extension when it has none', function () {
    app(SignatureStore::class)->save(CompanyCode::SDC, new SignaturePerson('Laura Gómez', 'Analista de Cartera', '+57 310 555 0101'), null);

    $text = VisibleText::of(cobroSignedMail(RunKind::Weekly, CompanyCode::SDC)->html);

    expect($text)->toContain('Cordialmente, Laura Gómez Analista de Cartera Móvil: +57 310 555 0101 PBX: +57 (60-1) 3713766 cartera@derivadoscol.com')
        ->not->toContain('Ext.')
        ->not->toContain('María Paula Alvarez');
});

it('escapes the person data', function () {
    app(SignatureStore::class)->save(CompanyCode::SGX, new SignaturePerson('<b>Ana</b> & Cía', 'Cartera', '+573105550101'), null);

    expect(cobroSignedMail(RunKind::Weekly, CompanyCode::SGX)->html)->toContain('&lt;b&gt;Ana&lt;/b&gt; &amp; Cía')->not->toContain('<b>Ana</b>');
});

it('sends the images inline with the run mails', function (string $command, RunKind $kind) {
    $this->artisan($command, ['--empresa' => ['SGX']])->assertSuccessful();

    $mail = collect($this->fakes->mail->sent())->sole(fn (OutgoingMail $mail): bool => $mail->clientId === $this->portfolio->tornillo->summary->id);

    expect(cobroCids($mail->html))->toBe(['sgx-redes', 'sgx-logo'])
        ->and(array_map(fn (MailAttachment $attachment): ?string => $attachment->contentId, $mail->attachments))->toBe(['sgx-redes', 'sgx-logo']);
})->with([
    'weekly' => ['cobro:cobro-semanal', RunKind::Weekly],
    'circularization' => ['cobro:circularizacion', RunKind::Circularization],
]);

it('sends the images inline with the test mail too', function () {
    $analyst = CobroUsers::create(Role::PortfolioAnalyst, email: 'maria.rojas@segurex.com');

    $this->actingAs($analyst)->post('/cobro/semanal/SGX/prueba', ['cliente' => $this->portfolio->tornillo->summary->id])->assertSessionHas('status');

    $mail = $this->fakes->mail->sent()[0];

    expect(cobroCids($mail->html))->toBe(['sgx-redes', 'sgx-logo'])
        ->and($mail->attachments)->toHaveCount(2);
});

it('shows the images of the signature in the browser previews, which cannot read cid:', function (string $url) {
    $html = (string) $this->actingAs(CobroUsers::create(Role::PortfolioAnalyst))->get($url)->assertOk()->getContent();

    expect($html)->not->toContain('cid:')
        ->toContain('src="data:image/png;base64,'.base64_encode((string) file_get_contents(app_path('Modules/Cobro/resources/firmas/sgx-logo.png'))).'"');
})->with([
    'weekly mail' => [fn () => '/cobro/semanal/SGX/correo/'.test()->portfolio->tornillo->summary->id],
    'circularization mail' => [fn () => '/cobro/circularizacion/SGX/correo/'.test()->portfolio->tornillo->summary->id],
    'template preview' => ['/cobro/plantillas/SGX/vista-previa'],
]);

it('leaves the old written signature out of the default templates', function (RunKind $kind, CompanyCode $company) {
    expect(TemplateStore::default($kind, $company)->closing)->not->toContain('Cordialmente')->not->toContain('María Paula Alvarez');
})->with([
    [RunKind::Weekly, CompanyCode::SGX],
    [RunKind::Weekly, CompanyCode::SDC],
    [RunKind::Circularization, CompanyCode::SGX],
    [RunKind::Circularization, CompanyCode::SDC],
]);
