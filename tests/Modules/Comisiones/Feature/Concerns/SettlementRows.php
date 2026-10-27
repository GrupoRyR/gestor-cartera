<?php

namespace Tests\Modules\Comisiones\Feature\Concerns;

use App\Modules\Comisiones\Domain\Settlement\SettlementStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Version rows written straight to comisiones_liquidaciones, with a
 * placeholder result, for the tests that never read the result back or
 * that need it to fail its check.
 */
final class SettlementRows
{
    /** Version 1 of the period, whose placeholder result matches its fingerprint. */
    public static function placeholder(string $period, SettlementStatus $status): SettlementRecord
    {
        return self::create($period, $status, self::fingerprint('placeholder'));
    }

    /**
     * The fingerprint ResultSerializer must give a stored text: its
     * HMAC-SHA256 under a key derived from $appKey (the current app key by
     * default), worked out here on its own so the tests check the serializer.
     */
    public static function fingerprint(string $payload, ?string $appKey = null): string
    {
        $key = $appKey ?? (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(Str::after($key, 'base64:'));
        }

        return hash_hmac('sha256', $payload, hash_hmac('sha256', 'comisiones.liquidacion', $key, true));
    }

    /** Version 1 of the period, whose stored result fails its fingerprint. */
    public static function corrupt(string $period, SettlementStatus $status = SettlementStatus::Draft): SettlementRecord
    {
        return self::create($period, $status, str_repeat('0', 64));
    }

    private static function create(string $period, SettlementStatus $status, string $sha256): SettlementRecord
    {
        return SettlementRecord::query()->create([
            'periodo' => $period,
            'version' => 1,
            'revision' => 1,
            'estado' => $status->value,
            'calculado_por' => 'ana@segurex.com',
            'calculado_en' => CarbonImmutable::parse('2026-08-01 08:00:00'),
            'justificaciones' => [],
            'resultado' => 'placeholder',
            'resultado_sha256' => $sha256,
            'total_nomina' => '0.00',
            'alertas_bloqueantes' => 0,
        ]);
    }
}
