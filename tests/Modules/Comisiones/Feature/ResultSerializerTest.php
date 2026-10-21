<?php

use App\Modules\Comisiones\Infrastructure\Settlement\ResultSerializer;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\BigRational;

/*
| The classes a stored result may build when it is read back: a static
| list, so decoding never scans nor autoloads the domain. This test keeps
| the list equal to the classes and enums under Comisiones\Domain.
*/

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
