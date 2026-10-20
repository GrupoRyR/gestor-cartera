<?php

use App\Modules\Cobro\Domain\Templates\SpanishList;

it('joins items the way a Spanish sentence lists them', function (array $items, string $sentence) {
    expect(SpanishList::join($items))->toBe($sentence);
})->with([
    'none' => [[], ''],
    'one' => [['9952'], '9952'],
    'two' => [['9952', '10403'], '9952 y 10403'],
    'three' => [['9952', '9960', '10010'], '9952, 9960 y 10010'],
]);
