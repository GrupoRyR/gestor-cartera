<?php

namespace App\Modules\Soportes\Http\Requests;

use App\Modules\Soportes\Application\Upload\NewReceipt;
use App\Modules\Soportes\Domain\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/**
 * POST /soportes: the client, the overdue invoices the payment covers (by
 * document number), the amount in pesos, the payment date (not after
 * today), the optional method and note, and 1 to 3 files, each a PDF, JPG
 * or PNG by name and by content (sniffed from the bytes), up to the size in
 * config('soportes.max_file_kilobytes'). The route already checked
 * soportes.upload; whether the client and invoices are really the user's to
 * pick is UploadReceipt's rule.
 */
final class StoreReceiptRequest extends FormRequest
{
    /**
     * Digits, without thousands separators, with up to two decimals; 13
     * integer digits fit the decimal(15,2) column.
     */
    private const string AMOUNT = '/^\d{1,13}(\.\d{1,2})?$/';

    /**
     * Back to the form with the errors and the input, except "archivos": a
     * plain field of that name (the default flash keeps it) would come back
     * as the old value of the file field.
     *
     * @throws ValidationException Always.
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        throw new ValidationException($validator, redirect($this->getRedirectUrl())
            ->withInput($this->except('archivos'))
            ->withErrors($validator->errors()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cliente' => ['required', 'integer', 'min:1'],
            'facturas' => ['required', 'array', 'min:1'],
            'facturas.*' => ['required', 'string', 'distinct', 'max:40'],
            'valor' => ['required', 'numeric', 'regex:'.self::AMOUNT, 'gt:0'],
            'fecha_pago' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'metodo' => ['nullable', Rule::enum(PaymentMethod::class)],
            'nota' => ['nullable', 'string', 'max:1000'],
            'archivos' => ['required', 'array', 'min:1', 'max:'.(int) config('soportes.max_files')],
            'archivos.*' => [
                'required',
                'file',
                'extensions:'.implode(',', (array) config('soportes.allowed_extensions')),
                'mimetypes:'.implode(',', (array) config('soportes.allowed_mimetypes')),
                'max:'.(int) config('soportes.max_file_kilobytes'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('soportes::carga.atributos');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'facturas.required' => __('soportes::carga.validacion.facturas'),
            'facturas.*.distinct' => __('soportes::carga.validacion.factura_repetida'),
            'valor.regex' => __('soportes::carga.validacion.valor'),
            'valor.gt' => __('soportes::carga.validacion.valor_positivo'),
            'fecha_pago.before_or_equal' => __('soportes::carga.validacion.fecha_futura'),
            'archivos.required' => __('soportes::carga.validacion.archivos'),
            'archivos.max' => __('soportes::carga.validacion.archivos_max', ['max' => (int) config('soportes.max_files')]),
            'archivos.*.extensions' => __('soportes::carga.validacion.formato'),
            'archivos.*.mimetypes' => __('soportes::carga.validacion.formato'),
            'archivos.*.max' => __('soportes::carga.validacion.peso', ['mb' => intdiv((int) config('soportes.max_file_kilobytes'), 1024)]),
        ];
    }

    public function clientId(): int
    {
        return $this->integer('cliente');
    }

    public function toNewReceipt(): NewReceipt
    {
        $method = $this->string('metodo')->toString();
        $note = $this->string('nota')->trim()->toString();

        return new NewReceipt(
            clientId: $this->clientId(),
            documentNumbers: array_values(array_map(fn (mixed $number): string => (string) $number, (array) $this->input('facturas'))),
            amount: $this->string('valor')->toString(),
            paidAt: CarbonImmutable::createFromFormat('!Y-m-d', $this->string('fecha_pago')->toString(), 'America/Bogota')
                ?: throw new UnexpectedValueException('The payment date passed date_format but did not parse.'),
            files: array_values(array_filter((array) $this->file('archivos'), fn (mixed $file): bool => $file instanceof UploadedFile)),
            method: $method === '' ? null : PaymentMethod::from($method),
            note: $note === '' ? null : $note,
        );
    }

    /**
     * A refused form goes back to the form of its client (step 2), or to
     * the client search when the client is missing.
     */
    protected function getRedirectUrl(): string
    {
        $client = $this->clientId();

        return $client > 0 ? route('soportes.create', ['cliente' => $client]) : route('soportes.create');
    }
}
