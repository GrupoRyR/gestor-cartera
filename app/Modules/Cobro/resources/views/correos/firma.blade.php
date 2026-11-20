@use('App\Modules\Core\Contracts\Enums\CompanyCode')

{{--
    The cartera signature at the end of a Cobro mail (weekly collection,
    circularization and replies), as each company signs today: the person
    of /cobro/firma ($person: SignaturePerson), the company's fixed data
    (cobro::firma.correo.<company>) and its PNG images ($images:
    SignatureImages::for(), shown with cid: references to the inline
    attachments of SignatureImages::attachments()). $company: Company.

    It sets the Helvetica stack itself, so a reply (outside the mail frame)
    keeps it. Brand v2 colors from the palette: the person's name and the
    company name in ink, the deep color; the rest in the text color; the
    neutral gray #6a6b6e (5.33:1) for the legal notice.
--}}
@php
    $palette = $company->palette;
    $code = $company->code->value;
    $data = fn (string $key): string => (string) __("cobro::firma.correo.{$code}.{$key}");
    $pbx = $person->extension === null
        ? __('cobro::firma.correo.pbx', ['pbx' => $data('pbx')])
        : __('cobro::firma.correo.pbx_extension', ['pbx' => $data('pbx'), 'extension' => $person->extension]);
    $sgx = $company->code === CompanyCode::SGX;
@endphp
<div data-firma="{{ $code }}" style="margin:18px 0 0;font-family:Helvetica,Arial,'Helvetica Neue',sans-serif;font-size:13px;line-height:1.5;color:{{ $palette->text }};">
    <p style="margin:0 0 14px;">{{ __('cobro::firma.correo.despedida') }}</p>
    <p style="margin:0 0 14px;">
        <strong style="color:{{ $palette->ink }};">{{ $person->name }}</strong><br>
        {{ $person->title }}
        @if ($sgx)
            <br><strong style="color:{{ $palette->ink }};">{{ $data('empresa') }}</strong><br>
            {{ $data('direccion') }}<br>
            {{ $data('ciudad') }}
        @endif
    </p>
    <p style="margin:0 0 14px;">
        {{ __('cobro::firma.correo.movil', ['movil' => $person->mobile]) }}<br>
        {{ $pbx }}<br>
        <a href="mailto:{{ $data('correo') }}" style="color:{{ $palette->text }};">{{ $data('correo') }}</a>
        @if ($sgx)
            <br><a href="{{ $data('web_url') }}" style="color:{{ $palette->ink }};font-weight:bold;">{{ $data('web') }}</a>
        @endif
    </p>
    @unless ($sgx)
        <p style="margin:0 0 14px;">
            <strong style="color:{{ $palette->ink }};">{{ $data('empresa') }}</strong><br>
            {{ $data('direccion') }}<br>
            {{ $data('ciudad') }}
        </p>
    @endunless
    @foreach ($images as $image)
        <p style="margin:0 0 10px;">
            <img src="cid:{{ $image['cid'] }}" width="{{ $image['width'] }}" height="{{ $image['height'] }}" alt="{{ $image['alt'] }}" style="display:block;border:0;max-width:100%;height:auto;">
        </p>
    @endforeach
    @if ($sgx)
        <p style="margin:14px 0;">{{ $data('ambiente') }}</p>
        <p style="margin:0;font-size:11px;line-height:1.4;color:#6a6b6e;">
            <strong>{{ $data('aviso_legal_titulo') }}</strong>
            {{ $data('aviso_legal') }}
            <a href="mailto:{{ $data('aviso_legal_correo') }}" style="color:#6a6b6e;">{{ $data('aviso_legal_correo') }}</a>.
        </p>
    @endif
</div>
