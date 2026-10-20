@use('App\Modules\Cobro\Domain\Runs\WeeklyTable')
@use('App\Support\Formatting\DateFormat')
@use('App\Support\Formatting\Money')

{{--
    The weekly collection mail (4.1), in the format each company sends
    today: its opening text, the table of every open invoice of the client
    (WeeklyTable: the company's columns; overdue invoices highlighted; the
    company name where the original has its logo) with the total, and its
    closing text. $company: Company; $data: NoticeData; $subject;
    $introHtml and $closingHtml: paragraphs as safe HTML (NoticeRenderer
    escaped them); $test; $draft.
--}}
@php
    $palette = $company->palette;
    $columns = WeeklyTable::columns($company->code);
    $brandHeader = WeeklyTable::headerStyle($company->code) === WeeklyTable::HEADER_BRAND;
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
                @php($overdue = $data::isOverdue($invoice))
                <tr data-overdue="{{ $overdue ? '1' : '0' }}" style="{{ $overdue ? 'background:#fde2e2;' : 'background:#ffffff;' }}">
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

    @foreach ($closingHtml as $paragraph)
        <p style="margin:0 0 14px;">{!! $paragraph !!}</p>
    @endforeach
</x-cobro::correo>
