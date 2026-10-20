<?php

namespace App\Modules\Comisiones\Infrastructure\Settlement;

use App\Modules\Comisiones\Domain\Calculation\SettlementResult;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\BigRational;
use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Turns a SettlementResult into the text stored in comisiones_liquidaciones
 * and back: serialize, gzcompress and base64, fingerprinted with sha256.
 *
 * Reading back checks the fingerprint first and unserializes only the
 * engine's own classes (everything under Comisiones\Domain), dates and the
 * Brick\Math numbers, so a tampered row never builds another object.
 */
final class ResultSerializer
{
    /** @var list<class-string>|null */
    private static ?array $allowed = null;

    /**
     * @return array{string, string} The stored text and its sha256.
     */
    public function encode(SettlementResult $result): array
    {
        $payload = base64_encode((string) gzcompress(serialize($result)));

        return [$payload, hash('sha256', $payload)];
    }

    /**
     * @param  string  $period  "YYYY-MM", for the error.
     *
     * @throws CorruptSettlement when the fingerprint differs or the text is not a result.
     */
    public function decode(string $payload, string $sha256, string $period, int $version): SettlementResult
    {
        if (! hash_equals($sha256, hash('sha256', $payload))) {
            throw CorruptSettlement::fingerprint($period, $version);
        }

        try {
            $compressed = base64_decode($payload, true);
            $serialized = $compressed === false ? false : gzuncompress($compressed);
            $result = $serialized === false ? null : unserialize($serialized, ['allowed_classes' => self::allowedClasses()]);
        } catch (Throwable $error) {
            throw CorruptSettlement::unreadable($period, $version, $error);
        }

        if (! $result instanceof SettlementResult) {
            throw CorruptSettlement::unreadable($period, $version);
        }

        return $result;
    }

    /**
     * Every class and enum of the engine's domain, the date classes and the
     * Brick\Math numbers.
     *
     * @return list<class-string>
     */
    public static function allowedClasses(): array
    {
        if (self::$allowed !== null) {
            return self::$allowed;
        }

        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'Domain';
        $classes = [DateTimeImmutable::class, DateTimeZone::class, BigNumber::class, BigDecimal::class, BigInteger::class, BigRational::class];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'App\\Modules\\Comisiones\\Domain\\'.str_replace(['/', '\\'], '\\', $relative);

            if (class_exists($class) || enum_exists($class)) {
                $classes[] = $class;
            }
        }

        return self::$allowed = $classes;
    }
}
