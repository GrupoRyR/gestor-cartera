<?php

use Illuminate\Support\Facades\DB;

it('sets the Colombian offset on the MySQL and MariaDB sessions', function () {
    expect(config('database.connections.mysql.timezone'))->toBe('-05:00')
        ->and(config('database.connections.mariadb.timezone'))->toBe('-05:00');
});

it('runs MySQL sessions in the Colombian offset', function () {
    $connection = DB::connection();

    if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Needs MySQL or MariaDB (CI); local runs use SQLite, which has no session time zone.');
    }

    expect($connection->scalar('select @@session.time_zone'))->toBe('-05:00');
});
