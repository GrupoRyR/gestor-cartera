<?php

namespace App\Modules\Cobro\Domain\Runs;

use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * The invoice table of each company's weekly collection mail, as the
 * company sends it today: its columns, in order, with the headers as they
 * read in the original (no accents). The Mora and Moneda columns of the
 * original are left out until the cartera carries those values.
 */
final class WeeklyTable
{
    /**
     * Header style: the company's button color behind white text.
     */
    public const string HEADER_BRAND = 'brand';

    /**
     * Header style: light grey behind dark text.
     */
    public const string HEADER_NEUTRAL = 'neutral';

    /**
     * @return list<array{key: string, label: string, align: 'left'|'right'|'center'}>
     */
    public static function columns(CompanyCode $company): array
    {
        $left = fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'align' => 'left'];
        $center = fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'align' => 'center'];
        $right = fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'align' => 'right'];

        return match ($company) {
            CompanyCode::SGX => [
                $left('comercial', 'Comercial'),
                $center('cod_cliente', 'Cod. Cliente'),
                $left('razon_social', 'Razon social'),
                $right('nit', 'NIT'),
                $right('documento', 'Doc'),
                $center('fecha_factura', 'Fecha Factura'),
                $center('condiciones_pago', 'Condiciones de Pago'),
                $center('fecha_vencimiento', 'Fecha Vencimiento'),
                $center('dias', 'Dias'),
                $right('total', 'Total'),
            ],
            CompanyCode::SDC => [
                $left('nit', 'NIT'),
                $left('unidad_negocio', 'Unidad de Negocio'),
                $center('cod_cliente', 'Cod. Cliente'),
                $left('razon_social', 'Razon Social'),
                $center('documento', 'No. FV'),
                $center('fecha_factura', 'Fecha Factura'),
                $center('condiciones_pago', 'Condiciones de pago'),
                $center('fecha_vencimiento', 'Fecha Vencimiento'),
                $center('dias', 'dias'),
                $right('total', 'Total'),
            ],
        };
    }

    public static function headerStyle(CompanyCode $company): string
    {
        return $company === CompanyCode::SGX ? self::HEADER_BRAND : self::HEADER_NEUTRAL;
    }
}
