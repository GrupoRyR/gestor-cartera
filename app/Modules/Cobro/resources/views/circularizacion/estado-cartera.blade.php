@use('App\Modules\Cobro\Circularizacion\CircularizationTable')
@use('App\Modules\Cobro\Circularizacion\ClosingNotice')
@use('App\Modules\Core\Contracts\Enums\CompanyCode')
@use('App\Support\Formatting\DateFormat')
@use('App\Support\Formatting\Money')

{{--
    The monthly circularization mail (4.2), in the format each company
    sends today: its opening text, the table of every open item of the
    client at the cutoff (CircularizationTable: the company's columns; the
    company name where the original has its logo) with the total, Segurex's
    notice about payments made after the cutoff, the closing text and
    Segurex's signature footer. $company: Company; $data: NoticeData;
    $subject; $introHtml and $closingHtml: paragraphs as safe HTML
    (NoticeRenderer escaped them); $test; $draft.

    Inline colors only (mail clients ignore stylesheets): the company's
    BrandPalette button (white text on it, AA), the ryr-design failure red
    #b42318 (6.5:1 on white) for the notice and neutral greys.
--}}
@php
    $palette = $company->palette;
    $columns = CircularizationTable::columns($company->code);
    $brandHeader = CircularizationTable::headerStyle($company->code) === CircularizationTable::HEADER_BRAND;
    $head = $brandHeader
        ? "background:{$palette->button};color:#ffffff;"
        : 'background:#d9d9d9;color:#111111;';
    $cell = 'padding:4px 6px;border:1px solid #d9d9d9;font-size:12px;';
    // Only names may wrap; codes, dates, days and amounts stay on one line.
    $wrapping = ['comercial', 'razon_social', 'unidad_negocio'];
    $value = fn (string $key, $invoice): string => match ($key) {
        'comercial' => $data->advisorLabel($invoice),
        'cod_cliente' => $data->clientCode,
        'razon_social' => $data->clientName,
        'nit' => $data->taxId,
        'unidad_negocio' => (string) $data->group,
        'documento' => $invoice->documentNumber,
        'fecha_factura' => DateFormat::date($invoice->issuedAt),
        'condiciones_pago' => (string) $data->paymentTerms,
        'fecha_vencimiento' => DateFormat::date($invoice->dueAt),
        'dias' => (string) $invoice->daysOverdue,
        'total' => Money::format($invoice->balance),
        default => '',
    };
    $notice = ClosingNotice::for($company->code, $data->cutoffDate);
    $segurexFooter = $company->code === CompanyCode::SGX;
@endphp
<x-cobro::correo :company="$company" :title="$subject" :test="$test" :draft="$draft" :framed="false">
    @foreach ($introHtml as $paragraph)
        <p style="margin:0 0 14px;">{!! $paragraph !!}</p>
    @endforeach

    <table role="table" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:8px 0 18px;max-width:1000px;">
        <caption style="caption-side:top;text-align:left;padding:10px 6px;font-size:22px;font-weight:bold;color:{{ $palette->button }};">{{ $company->name }}</caption>
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th scope="col" align="center" style="{{ $cell }}{{ $head }}font-weight:bold;">{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($data->invoices as $invoice)
                <tr style="background:#ffffff;">
                    @foreach ($columns as $column)
                        <td align="{{ $column['align'] }}" style="{{ $cell }}{{ in_array($column['key'], $wrapping, true) ? '' : 'white-space:nowrap;' }}">{{ $value($column['key'], $invoice) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="{{ count($columns) - 1 }}" style="padding:4px 6px;"></td>
                <td align="right" style="{{ $cell }}{{ $head }}font-weight:bold;white-space:nowrap;">{{ Money::format($data->total()) }}</td>
            </tr>
        </tfoot>
    </table>

    @if ($notice !== null)
        <p data-aviso="cierre" style="margin:0 0 18px;color:#b42318;font-weight:bold;">{{ $notice }}</p>
    @endif

    @foreach ($closingHtml as $paragraph)
        <p style="margin:0 0 14px;">{!! $paragraph !!}</p>
    @endforeach

    @if ($segurexFooter)
        <div data-pie="segurex" style="margin:18px 0 0;font-size:13px;color:#1f2937;">
            <p style="margin:0 0 14px;">
                <strong>{{ __('cobro::circularizacion.segurex.pie.empresa') }}</strong><br>
                {{ __('cobro::circularizacion.segurex.pie.direccion') }}<br>
                {{ __('cobro::circularizacion.segurex.pie.ciudad') }}<br>
                {{ __('cobro::circularizacion.segurex.pie.movil') }}<br>
                {{ __('cobro::circularizacion.segurex.pie.pbx') }}
            </p>
            <p style="margin:0 0 14px;">
                <a href="mailto:{{ __('cobro::circularizacion.segurex.pie.correo') }}" style="color:#1f2937;">{{ __('cobro::circularizacion.segurex.pie.correo') }}</a><br>
                <a href="{{ __('cobro::circularizacion.segurex.pie.web_url') }}" style="color:{{ $palette->button }};font-weight:bold;">{{ __('cobro::circularizacion.segurex.pie.web') }}</a>
            </p>
            <p style="margin:0 0 14px;color:{{ $palette->button }};">{{ __('cobro::circularizacion.segurex.pie.ambiente') }}</p>
            <p style="margin:0;font-size:11px;line-height:1.4;color:#5e6168;">
                <strong>{{ __('cobro::circularizacion.segurex.pie.aviso_legal_titulo') }}</strong>
                {{ __('cobro::circularizacion.segurex.pie.aviso_legal') }}
                <a href="mailto:{{ __('cobro::circularizacion.segurex.pie.aviso_legal_correo') }}" style="color:#5e6168;">{{ __('cobro::circularizacion.segurex.pie.aviso_legal_correo') }}</a>.
            </p>
        </div>
    @endif
</x-cobro::correo>
