<?php

use App\Modules\Comisiones\Domain\Shared\PersonRef;
use App\Modules\Comisiones\Domain\Shared\SellerCode;

describe('fromSapText', function () {
    it('reads the leading number of the SAP seller text', function (string $text, ?int $expected) {
        expect(SellerCode::fromSapText($text))->toBe($expected);
    })->with([
        'regular seller' => ['14 MONICA RIVERA AREVALO', 14],
        'leading space and zero' => [' 085 LILIAM', 85],
        'code only' => ['100', 100],
        'no space after the number' => ['16-CAROLINA', 16],
        'no seller' => ['SIN ASESOR', null],
        'empty' => ['', null],
        'number after the name' => ['ASESOR 14', null],
        'absurdly long number' => ['12345678901234567890 X', null],
    ]);
});

describe('PersonRef', function () {
    it('keeps the id and the name', function () {
        $person = new PersonRef('monica-rivera', 'Mónica Rivera');

        expect($person->id)->toBe('monica-rivera')
            ->and($person->name)->toBe('Mónica Rivera');
    });

    it('rejects a blank id or name', function (string $id, string $name) {
        new PersonRef($id, $name);
    })->throws(InvalidArgumentException::class)->with([
        'blank id' => ['  ', 'Mónica Rivera'],
        'blank name' => ['monica-rivera', ''],
    ]);
});
