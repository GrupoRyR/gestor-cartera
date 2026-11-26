<?php

namespace App\Modules\Soportes\Domain\Exceptions;

use DomainException;

/**
 * An upload the rules refuse even though the form was well formed: the
 * client is not one the user may upload for, a document is not an open
 * invoice of that client, or a file is not really a PDF, JPG or PNG.
 *
 * $field names the form field the refusal belongs to and $reason one of the
 * constants below, so the screen shows its own text. The message is for the
 * logs.
 */
final class ReceiptRefused extends DomainException
{
    public const string CLIENT_NOT_FOUND = 'cliente_no_encontrado';

    public const string INVOICE_NOT_OPEN = 'factura_no_abierta';

    public const string FILE_NOT_ACCEPTED = 'archivo_no_aceptado';

    private function __construct(
        public readonly string $field,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function clientNotFound(int $clientId): self
    {
        return new self('cliente', self::CLIENT_NOT_FOUND, "Client {$clientId} does not exist or is out of the user's scope.");
    }

    /**
     * @param  list<string>  $documentNumbers
     */
    public static function invoicesNotOpen(int $clientId, array $documentNumbers): self
    {
        return new self(
            'facturas',
            self::INVOICE_NOT_OPEN,
            'Not open invoices of client '.$clientId.': '.implode(', ', $documentNumbers).'.',
        );
    }

    public static function fileNotAccepted(string $mime): self
    {
        return new self('archivos', self::FILE_NOT_ACCEPTED, "A receipt file of type [{$mime}] is not accepted.");
    }
}
