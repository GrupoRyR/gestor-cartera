<?php

namespace App\Modules\Soportes\Application\Upload;

use App\Modules\Soportes\Domain\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * What the user sends to upload a receipt, already validated for shape by
 * the form: UploadReceipt still checks the client, the invoices and the
 * files against the portfolio and their content.
 */
final readonly class NewReceipt
{
    /**
     * @param  int  $clientId  Core's client id (ClientSummary::$id).
     * @param  list<string>  $documentNumbers  SAP document numbers of the overdue invoices the payment covers.
     * @param  string  $amount  Amount paid in Colombian pesos: digits with up to two decimals, above zero.
     * @param  CarbonImmutable  $paidAt  Day the client paid.
     * @param  list<UploadedFile>  $files  One or more PDF, JPG or PNG files.
     *
     * @throws InvalidArgumentException When there is no document or file, or the amount is not a positive
     *                                  number with up to two decimals that fits 13 integer digits.
     */
    public function __construct(
        public int $clientId,
        public array $documentNumbers,
        public string $amount,
        public CarbonImmutable $paidAt,
        public array $files,
        public ?PaymentMethod $method = null,
        public ?string $note = null,
    ) {
        if ($documentNumbers === [] || $files === []) {
            throw new InvalidArgumentException('A receipt needs at least one invoice and one file.');
        }

        if (preg_match('/^\d{1,13}(\.\d{1,2})?$/', $amount) !== 1 || (float) $amount <= 0) {
            throw new InvalidArgumentException("The receipt amount [{$amount}] must be above zero with up to two decimals.");
        }
    }
}
