<?php

use App\Modules\Cobro\Domain\Runs\NoticeData;
use App\Modules\Cobro\Domain\Runs\RenderedMail;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Domain\Templates\TemplateContent;
use App\Modules\Cobro\Infrastructure\Mail\NoticeRenderer;
use App\Modules\Cobro\Infrastructure\Templates\TemplateStore;
use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Data\Invoice;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Tests\Fakes\Make;
use Tests\Modules\Cobro\Support\VisibleText;

/*
 * The weekly collection mail in the format each company sends today
 * (the Segurex and Derivados mails of 07/10/2026 and 05/08/2026).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 15:46:00', 'America/Bogota'));
});

function cobroFormatInvoice(string $number, string $issued, string $due, int $days, float $balance, CompanyCode $company = CompanyCode::SGX): Invoice
{
    return Make::invoice(
        company: $company,
        documentNumber: $number,
        issuedAt: CarbonImmutable::parse($issued, 'America/Bogota'),
        dueAt: CarbonImmutable::parse($due, 'America/Bogota'),
        amount: $balance,
        daysOverdue: $days,
        advisorCode: 41,
    );
}

/**
 * The client of the original Segurex mail: one overdue invoice and two
 * not due yet.
 *
 * @param  list<Invoice>|null  $invoices
 */
function cobroFormatNotice(?array $invoices = null, string $name = 'GALVIS ANAYA JUAN DE JESUS'): NoticeData
{
    return new NoticeData(
        clientName: $name,
        taxId: '13846973-1',
        invoices: $invoices ?? [
            cobroFormatInvoice('9952', '2026-08-26', '2026-09-26', 11, 4_732_906.08),
            cobroFormatInvoice('10403', '2026-09-25', '2026-10-25', -18, 8_813_138.54),
            cobroFormatInvoice('10471', '2026-09-30', '2026-10-30', -23, 5_398_502.14),
        ],
        cutoffDate: CarbonImmutable::parse('2026-10-07', 'America/Bogota'),
        clientCode: 'CN0294',
        group: 'DISTRIBUCION',
        paymentTerms: '30 DIAS',
        advisorNames: [41 => 'JENNY ROCIO BUITRAGO IBAÑEZ'],
        advisorCode: 41,
    );
}

function cobroFormatRender(CompanyCode $company, ?NoticeData $notice = null): RenderedMail
{
    return app(NoticeRenderer::class)->render(
        'cobro::correos.cobro-semanal',
        Make::company(code: $company),
        $notice ?? cobroFormatNotice(),
        TemplateStore::default(RunKind::Weekly, $company),
    );
}

/**
 * The header texts of the invoice table, in order.
 *
 * @return list<string>
 */
function cobroFormatHeaders(string $html): array
{
    preg_match_all('/<th scope="col"[^>]*>(.*?)<\/th>/s', $html, $matches);

    return array_map(fn (string $cell): string => VisibleText::of($cell), $matches[1]);
}

it('writes the Segurex texts word for word, with the client data', function () {
    $mail = cobroFormatRender(CompanyCode::SGX);
    $text = VisibleText::of($mail->html);

    expect($mail->subject)->toBe('Cobro de cartera - Segurex LATAM SAS')
        ->and($text)->toContain('Buen día Estimado cliente GALVIS ANAYA JUAN DE JESUS Espero que se encuentre bien.')
        ->toContain('Nos dirigimos a usted para recordarte que su cuenta con corte a 07/10/2026 presenta un saldo pendiente por $ 4.732.906 correspondiente a la factura 9952 con vencimiento el 26/09/2026 El saldo aún no ha sido abonado')
        ->toContain('A continuación, le proporcionamos los detalles del estado de su cartera a la fecha:')
        ->toContain('los correos cartera@segurex.com y miguel.martinez@segurex.com o a la línea celular y WhatsApp 3176490246.')
        ->toContain('Agradecemos su pronta atención a este asunto. Cordialmente, María Paula Alvarez Auxiliar de Cartera')
        ->and($mail->html)->toContain('Estimado cliente <strong>GALVIS ANAYA JUAN DE JESUS</strong>')
        ->toContain('<strong>corte a 07/10/2026</strong>')
        ->toContain('a la factura <strong>9952</strong> con <strong>vencimiento el 26/09/2026</strong>');
});

it('lists every open invoice in the Segurex columns, overdue highlighted, with the total', function () {
    $html = cobroFormatRender(CompanyCode::SGX)->html;
    $palette = BrandPalette::official(CompanyCode::SGX);

    expect(cobroFormatHeaders($html))->toBe([
        'Comercial', 'Cod. Cliente', 'Razon social', 'NIT', 'Doc', 'Fecha Factura',
        'Condiciones de Pago', 'Fecha Vencimiento', 'Dias', 'Total',
    ])
        ->and(VisibleText::of($html))
        ->toContain('41 JENNY ROCIO BUITRAGO IBAÑEZ CN0294 GALVIS ANAYA JUAN DE JESUS 13846973-1 9952 26/08/2026 30 DIAS 26/09/2026 11 $ 4.732.906')
        ->toContain('10403 25/09/2026 30 DIAS 25/10/2026 -18 $ 8.813.139')
        ->toContain('$ 18.944.547')
        ->and(substr_count($html, 'data-overdue="1"'))->toBe(1)
        ->and(substr_count($html, 'data-overdue="0"'))->toBe(2)
        ->and($html)->toContain('background:'.$palette->button.';color:#ffffff;')
        ->not->toContain('Mora')
        ->not->toContain('Moneda');
});

it('writes the Derivados texts and columns, with the business unit', function () {
    $mail = cobroFormatRender(CompanyCode::SDC);
    $text = VisibleText::of($mail->html);

    expect($mail->subject)->toBe('Cobro de cartera - Soluciones y Derivados de Colombia S.A.S')
        ->and($text)->toContain('Estimado cliente GALVIS ANAYA JUAN DE JESUS')
        ->toContain('presenta un saldo pendiente por $ 4.732.906 correspondiente a la factura 9952 con vencimiento el 26/09/2026.')
        ->toContain('cartera@derivadoscol.com y miguel.martinez@derivadoscol.com o a la línea celular y WhatsApp 3176490246 o 3153654788.')
        ->toContain('María Paula Alvarez Auxiliar de Crédito y Cobranza Celular: +57 3153654788 E-mail: cartera@derivadoscol.com')
        ->and(cobroFormatHeaders($mail->html))->toBe([
            'NIT', 'Unidad de Negocio', 'Cod. Cliente', 'Razon Social', 'No. FV', 'Fecha Factura',
            'Condiciones de pago', 'Fecha Vencimiento', 'dias', 'Total',
        ])
        ->and($text)->toContain('13846973-1 DISTRIBUCION CN0294 GALVIS ANAYA JUAN DE JESUS 9952 26/08/2026 30 DIAS 26/09/2026 11 $ 4.732.906')
        ->not->toContain('JENNY ROCIO')
        ->and($mail->html)->toContain('background:#d9d9d9;color:#111111;');
});

it('names several overdue invoices and their due dates in one sentence', function () {
    $notice = cobroFormatNotice([
        cobroFormatInvoice('9952', '2026-08-26', '2026-09-26', 11, 1_000_000),
        cobroFormatInvoice('9960', '2026-08-26', '2026-09-26', 11, 500_000),
        cobroFormatInvoice('10010', '2026-09-01', '2026-10-01', 6, 250_000),
        cobroFormatInvoice('10403', '2026-09-25', '2026-10-25', -18, 900_000),
    ]);

    expect(VisibleText::of(cobroFormatRender(CompanyCode::SGX, $notice)->html))
        ->toContain('saldo pendiente por $ 1.750.000 correspondiente a las facturas 9952, 9960 y 10010 con vencimiento el 26/09/2026 y 01/10/2026');
});

it('lists credit notes so the total is what the client owes, never as overdue invoices', function () {
    $notice = cobroFormatNotice([
        cobroFormatInvoice('9952', '2026-08-26', '2026-09-26', 11, 4_732_906),
        cobroFormatInvoice('NC-77', '2026-08-01', '2026-08-31', 37, -1_000_000),
        cobroFormatInvoice('10403', '2026-09-25', '2026-10-25', -18, 500_000),
    ]);

    $html = cobroFormatRender(CompanyCode::SGX, $notice)->html;

    expect(VisibleText::of($html))
        ->toContain('saldo pendiente por $ 4.732.906 correspondiente a la factura 9952 con vencimiento el 26/09/2026')
        ->toContain('NC-77 01/08/2026 30 DIAS 31/08/2026 37 -$ 1.000.000')
        ->toContain('$ 4.232.906')
        ->and(substr_count($html, 'data-overdue="1"'))->toBe(1);
});

it('escapes the data before applying bold', function () {
    $mail = app(NoticeRenderer::class)->render(
        'cobro::correos.cobro-semanal',
        Make::company(),
        cobroFormatNotice(name: 'ACME <script>alert(1)</script> SAS'),
        new TemplateContent('**Cobro** {cliente}', 'Estimado **{cliente}** y **<b>fijo</b>**', 'Fin'),
    );

    expect($mail->subject)->toBe('Cobro ACME <script>alert(1)</script> SAS')
        ->and($mail->html)->not->toContain('<script>')
        ->toContain('<strong>ACME &lt;script&gt;alert(1)&lt;/script&gt; SAS</strong>')
        ->toContain('<strong>&lt;b&gt;fijo&lt;/b&gt;</strong>');
});

it('keeps line breaks inside a paragraph and splits at blank lines', function () {
    $mail = app(NoticeRenderer::class)->render(
        'cobro::correos.cobro-semanal',
        Make::company(),
        cobroFormatNotice(),
        new TemplateContent('Asunto', "Uno\nsigue\n\n\nDos", 'Fin'),
    );

    expect($mail->html)->toContain('<p style="margin:0 0 14px;">Uno<br>'."\nsigue</p>")
        ->toContain('<p style="margin:0 0 14px;">Dos</p>');
});

it('marks test and draft mails in the subject and the body', function () {
    $renderer = app(NoticeRenderer::class);
    $template = new TemplateContent('Cartera de {cliente}', 'Hola', 'Chao');

    $test = $renderer->render('cobro::correos.cobro-semanal', Make::company(), cobroFormatNotice(), $template, test: true);
    $draft = $renderer->render('cobro::correos.cobro-semanal', Make::company(), cobroFormatNotice(), $template, draft: true);

    expect($test->subject)->toBe('[Prueba] Cartera de GALVIS ANAYA JUAN DE JESUS')
        ->and($test->html)->toContain('data-banner="prueba"')
        ->and($draft->subject)->toBe('[Borrador] Cartera de GALVIS ANAYA JUAN DE JESUS')
        ->and($draft->html)->toContain('data-banner="borrador"');
});

it('shows the advisor code alone when its name is unknown', function () {
    $notice = new NoticeData('X', '1', [cobroFormatInvoice('1', '2026-08-26', '2026-09-26', 11, 1)], null, advisorCode: 7);

    expect($notice->advisorLabel(Make::invoice(advisorCode: 52)))->toBe('52')
        ->and($notice->advisorLabel(Make::invoice()))->toBe('7');
});
