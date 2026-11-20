<?php

namespace App\Modules\Cobro\Domain\Signatures;

/**
 * Who signs a company's Cobro mails: the part of the signature that
 * changes when another person takes care of the cartera. The company part
 * (legal name, address, PBX number, e-mail, web) is fixed.
 */
final readonly class SignaturePerson
{
    /**
     * @param  string|null  $extension  Extension of the company PBX; null = none.
     * @param  bool  $isDefault  Whether it comes from config cobro.firma (nobody saved one).
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $mobile,
        public ?string $extension = null,
        public bool $isDefault = false,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data  name, title, mobile and extension.
     */
    public static function fromArray(array $data, bool $isDefault = false): self
    {
        $extension = trim((string) ($data['extension'] ?? ''));

        return new self(
            trim((string) ($data['name'] ?? '')),
            trim((string) ($data['title'] ?? '')),
            trim((string) ($data['mobile'] ?? '')),
            $extension === '' ? null : $extension,
            $isDefault,
        );
    }

    /**
     * @return array{name: string, title: string, mobile: string, extension: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title,
            'mobile' => $this->mobile,
            'extension' => $this->extension,
        ];
    }
}
