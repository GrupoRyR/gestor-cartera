<?php

namespace App\Modules\Cobro\Infrastructure\Signatures;

use App\Modules\Core\Contracts\Data\MailAttachment;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use RuntimeException;

/**
 * The PNG images of each company's mail signature
 * (resources/firmas, from the companies' own signatures), in the order
 * they appear. They travel inside the mail as inline attachments that the
 * HTML shows with <img src="cid:{id}">: Outlook and Gmail block external
 * images and do not show SVG or base64 sources.
 */
final class SignatureImages
{
    /**
     * Content id => [file, width, height] per company, in display order.
     *
     * @var array<string, array<string, array{string, int, int}>>
     */
    private const array IMAGES = [
        'SGX' => [
            'sgx-redes' => ['sgx-redes.png', 405, 44],
            'sgx-logo' => ['sgx-logo.png', 432, 69],
        ],
        'SDC' => [
            'sdc' => ['sdc.png', 474, 255],
        ],
    ];

    /**
     * The images of $company for the signature view: content id, size and
     * alternative text.
     *
     * @return list<array{cid: string, width: int, height: int, alt: string}>
     */
    public static function for(CompanyCode $company): array
    {
        $images = [];

        foreach (self::IMAGES[$company->value] as $cid => [, $width, $height]) {
            $images[] = [
                'cid' => $cid,
                'width' => $width,
                'height' => $height,
                'alt' => (string) __("cobro::firma.correo.{$company->value}.imagenes.{$cid}"),
            ];
        }

        return $images;
    }

    /**
     * The inline attachments of $company's signature, in the same order.
     *
     * @return list<MailAttachment>
     */
    public static function attachments(CompanyCode $company): array
    {
        $attachments = [];

        foreach (self::IMAGES[$company->value] as $cid => [$file]) {
            $attachments[] = new MailAttachment($file, 'image/png', self::bytes($file), contentId: $cid);
        }

        return $attachments;
    }

    private static function bytes(string $file): string
    {
        $path = dirname(__DIR__, 2).'/resources/firmas/'.$file;
        $bytes = is_file($path) ? file_get_contents($path) : false;

        return $bytes === false ? throw new RuntimeException("The signature image {$file} is missing.") : $bytes;
    }
}
