<?php

namespace App\Modules\Core\Contracts\Data;

/**
 * A contact person of a client, loaded from SAP (OCRD and OCPR) through the
 * "contactos" batch. Every field may be missing in SAP.
 */
final readonly class Contact
{
    /**
     * @param  string|null  $name  Contact person, or null for the client's general data (OCRD).
     * @param  string|null  $email  E-mail address as stored in SAP (not validated).
     * @param  string|null  $phone  Phone number as stored in SAP.
     * @param  string|null  $position  Job title.
     * @param  bool  $isPrimary  The client's default contact; collection e-mails go here first.
     */
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $position = null,
        public bool $isPrimary = false,
    ) {}
}
