@use('App\Support\Formatting\DateFormat')
@use('App\Support\Formatting\Money')

{{--
    PROVISIONAL circularization mail (4.2): the statement of every open
    invoice of the client at close, inside the brand frame and marked as a
    draft. It is replaced by the format the clients get today when it
    arrives (owner: the "Circularización" session). $company: Company;
    $data: NoticeData (every open invoice); $subject; $intro and $closing:
    plain-text paragraphs; $test; $draft.
--}}
@php
    $palette = $company->palette;
    $notDue = $data->total() - $data->overdue();
@endphp
<x-cobro::correo :company="$company" :title="$subject" :test="$test" :draft="$draft">
    @foreach ($intro as $paragraph)
        <p style="margin:0 0 16px;">{!! nl2br(e($paragraph)) !!}</p>
    @endforeach

    <table role="table" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:8px 0 20px;font-size:14px;">
        <caption style="text-align:left;padding:0 0 8px;font-weight:bold;">{{ __('cobro::circularizacion.tabla.titulo') }}</caption>
        <thead>
            <tr>
                <th scope="col" align="left" style="padding:8px;background:{{ $palette->tint }};color:{{ $palette->ink }};">{{ __('cobro::circularizacion.tabla.documento') }}</th>
                <th scope="col" align="left" style="padding:8px;background:{{ $palette->tint }};color:{{ $palette->ink }};">{{ __('cobro::circularizacion.tabla.fecha') }}</th>
                <th scope="col" align="left" style="padding:8px;background:{{ $palette->tint }};color:{{ $palette->ink }};">{{ __('cobro::circularizacion.tabla.vence') }}</th>
                <th scope="col" align="right" style="padding:8px;background:{{ $palette->tint }};color:{{ $palette->ink }};">{{ __('cobro::circularizacion.tabla.dias') }}</th>
                <th scope="col" align="right" style="padding:8px;background:{{ $palette->tint }};color:{{ $palette->ink }};">{{ __('cobro::circularizacion.tabla.saldo') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data->invoices as $invoice)
                <tr>
                    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{{ $invoice->documentType }} {{ $invoice->documentNumber }}</td>
                    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{{ DateFormat::date($invoice->issuedAt) }}</td>
                    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{{ DateFormat::date($invoice->dueAt) }}</td>
                    <td align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;">{{ $invoice->daysOverdue > 0 ? number_format($invoice->daysOverdue, 0, ',', '.') : '—' }}</td>
                    <td align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;white-space:nowrap;">{{ Money::format($invoice->balance) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th scope="row" colspan="4" align="right" style="padding:8px;">{{ __('cobro::circularizacion.tabla.por_vencer') }}</th>
                <td align="right" style="padding:8px;white-space:nowrap;">{{ Money::format($notDue) }}</td>
            </tr>
            <tr>
                <th scope="row" colspan="4" align="right" style="padding:8px;">{{ __('cobro::circularizacion.tabla.vencido') }}</th>
                <td align="right" style="padding:8px;white-space:nowrap;">{{ Money::format($data->overdue()) }}</td>
            </tr>
            <tr>
                <th scope="row" colspan="4" align="right" style="padding:8px;">{{ __('cobro::circularizacion.tabla.total') }}</th>
                <td align="right" style="padding:8px;font-weight:bold;white-space:nowrap;">{{ Money::format($data->total()) }}</td>
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
