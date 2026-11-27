<?php

use Illuminate\Support\Facades\Schema;

it('names every index of the call tables within the 64 characters MySQL allows', function (string $table) {
    $names = array_column(Schema::getIndexes($table), 'name');

    expect($names)->not->toBeEmpty();

    foreach ($names as $name) {
        expect(strlen($name))->toBeLessThanOrEqual(64, "Index [{$name}] of [{$table}] is longer than 64 characters.");
    }
})->with(['cartera_calls', 'cartera_call_invoices']);
