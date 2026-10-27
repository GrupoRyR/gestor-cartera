<?php

use App\Modules\Comisiones\Application\DefaultParameters;
use App\Modules\Comisiones\Domain\Calculation\SettlementCalculator;
use App\Modules\Comisiones\Domain\Calculation\SettlementResult;
use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Settlement\CorruptSettlement;
use App\Modules\Comisiones\Infrastructure\Settlement\ResultSerializer;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\BigRational;
use Tests\Modules\Comisiones\Feature\Concerns\SettlementRows;
use Tests\Modules\Comisiones\Support\Lines;

/*
| The text a stored result is kept as, and the classes it may build when it
| is read back: a static list, so decoding never scans nor autoloads the
| domain. The text is fingerprinted with an HMAC under a key derived from
| the app key, so only the app can write a result it will read back.
*/

function serializerResult(): SettlementResult
{
    $july = Period::of(2026, 7);

    return (new SettlementCalculator)->calculate(
        new SettlementInput($july, sales: [Lines::sale(14, 69_915_134)], budgets: [Lines::budget(14, 77_241_857)]),
        DefaultParameters::book()->forPeriod($july),
    );
}

it('allows exactly the classes and enums of the domain, the dates and the Brick numbers', function () {
    $root = app_path('Modules/Comisiones/Domain');
    $domain = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $class = 'App\\Modules\\Comisiones\\Domain\\'.str_replace(['/', '\\'], '\\', substr($file->getPathname(), strlen($root) + 1, -4));

        if (class_exists($class) || enum_exists($class)) {
            $domain[] = $class;
        }
    }

    $expected = [...$domain, DateTimeImmutable::class, DateTimeZone::class, BigNumber::class, BigDecimal::class, BigInteger::class, BigRational::class];
    $allowed = ResultSerializer::allowedClasses();
    sort($expected);
    sort($allowed);

    expect($allowed)->toBe($expected);
});

it('fingerprints the text with an HMAC under a key derived from the app key and reads it back', function () {
    $serializer = app(ResultSerializer::class);
    $result = serializerResult();

    [$payload, $fingerprint] = $serializer->encode($result);

    expect($fingerprint)->toBe(SettlementRows::fingerprint($payload))
        ->and($fingerprint)->not->toBe(hash('sha256', $payload))
        ->and($serializer->decode($payload, $fingerprint, '2026-07', 1))->toEqual($result);
});

it('refuses a text fingerprinted with a plain sha256', function () {
    $serializer = app(ResultSerializer::class);
    [$payload] = $serializer->encode(serializerResult());

    expect(fn () => $serializer->decode($payload, hash('sha256', $payload), '2026-07', 1))
        ->toThrow(CorruptSettlement::class, 'does not match its fingerprint');
});

it('reads a text fingerprinted under a previous key while APP_PREVIOUS_KEYS lists it', function () {
    $serializer = app(ResultSerializer::class);
    $previous = 'base64:'.base64_encode(random_bytes(32));
    $result = serializerResult();
    [$payload] = $serializer->encode($result);
    config(['app.previous_keys' => ['base64:'.base64_encode(random_bytes(32)), $previous]]);

    expect($serializer->decode($payload, SettlementRows::fingerprint($payload, $previous), '2026-07', 1))->toEqual($result)
        // A new text is always fingerprinted under the current key.
        ->and($serializer->encode($result)[1])->toBe(SettlementRows::fingerprint($payload));
});

it('refuses a text fingerprinted under a key the app does not have', function () {
    $serializer = app(ResultSerializer::class);
    [$payload] = $serializer->encode(serializerResult());
    config(['app.previous_keys' => ['base64:'.base64_encode(random_bytes(32))]]);
    $unknown = 'base64:'.base64_encode(random_bytes(32));

    expect(fn () => $serializer->decode($payload, SettlementRows::fingerprint($payload, $unknown), '2026-07', 1))
        ->toThrow(CorruptSettlement::class);
});
