@use('App\Support\Formatting\DateFormat')
@use('App\Support\Formatting\Money')

{{--
    The weekly collection mail (4.1): the company's opening text, the table
    of overdue invoices with their total, and its closing text. $company:
    Company; $data: NoticeData (overdue invoices only); $subject; $intro
    and $closing: list of plain-text paragraphs, placeholders already
    filled (escaped here); $test: a test send.
--}}
@php($palette = $company->palette)
<x-cobro::correo :company="$company" :title="$subject" :test="$test" :draft="$draft">
    @foreach ($intro as $paragraph)
        <p style="margin:0 0 16px;">{!! nl2br(e($paragraph)) !!}</p>
    @endforeach

    <table role="table" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:8px 0 20px;font-size:14px;">
        <caption style="text-align:left;padding:0 0 8px;font-weight:bold;">{{ __('cobro::correos.tabla.titulo') }}</caption>
        <thead>
            <tr>
                <th scope="col" align="left" style="padding:8px;background:{{ $palette->tint }};color:{{ $palette->ink }};">{{ __('cobro::correos.tabla.documento') }}</th>
                <th scope="col" align="left" style="padding:8px;background:{{ $palette->tint }};color:{{ $palette->ink }};">{{ __('cobro::correos.tabla.vence') }}</th>
                <th scope="col" align="right" style="padding:8px;background:{{ $palette->tint }};color:{{ $palette->ink }};">{{ __('cobro::correos.tabla.dias') }}</th>
                <th scope="col" align="right" style="padding:8px;background:{{ $palette->tint }};color:{{ $palette->ink }};">{{ __('cobro::correos.tabla.saldo') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data->invoices as $invoice)
                <tr>
                    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{{ $invoice->documentType }} {{ $invoice->documentNumber }}</td>
                    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{{ DateFormat::date($invoice->dueAt) }}</td>
                    <td align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;">{{ number_format($invoice->daysOverdue, 0, ',', '.') }}</td>
                    <td align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;white-space:nowrap;">{{ Money::format($invoice->balance) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th scope="row" colspan="3" align="right" style="padding:8px;">{{ __('cobro::correos.tabla.total') }}</th>
                <td align="right" style="padding:8px;font-weight:bold;white-space:nowrap;">{{ Money::format($data->overdue()) }}</td>
            </tr>
        </tfoot>
    </table>

    @if ($data->cutoffDate !== null)
        <p style="margin:0 0 16px;color:#6b7280;font-size:13px;">{{ __('cobro::correos.corte', ['fecha' => DateFormat::date($data->cutoffDate)]) }}</p>
    @endif

    @foreach ($closing as $paragraph)
        <p style="margin:0 0 16px;">{!! nl2br(e($paragraph)) !!}</p>
    @endforeach
</x-cobro::correo>
