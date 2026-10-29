<?php

use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\Modules\Ingesta\Support\RobotRequests;

/*
| The robot endpoint takes at most 60 requests a minute from one IP and 120
| a minute from all of them together (audit S-19), so many IPs cannot make
| the server hash bodies without end. The unsigned requests below pass the
| throttle and stop at the signature check with 401.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 07:00:00', 'America/Bogota'));
    config(['ingesta.hmac_keys' => RobotRequests::KEY_ID.':'.RobotRequests::SECRET]);
});

function postUnsignedBatchFrom(string $ip): TestResponse
{
    return test()->call('POST', '/api/ingesta/asesores', [], [], [], [
        'REMOTE_ADDR' => $ip,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], '{}');
}

it('answers 429 to the 121st request of a minute, even from a new IP', function () {
    foreach (range(1, 120) as $attempt) {
        postUnsignedBatchFrom('10.0.'.intdiv($attempt, 250).'.'.($attempt % 250 + 1))->assertUnauthorized();
    }

    postUnsignedBatchFrom('192.168.1.1')->assertTooManyRequests();

    $this->travel(61)->seconds();

    postUnsignedBatchFrom('192.168.1.1')->assertUnauthorized();
});

it('still answers 429 to the 61st request of a minute from one IP, and only to that IP', function () {
    foreach (range(1, 60) as $attempt) {
        postUnsignedBatchFrom('10.0.0.1')->assertUnauthorized();
    }

    postUnsignedBatchFrom('10.0.0.1')->assertTooManyRequests();
    postUnsignedBatchFrom('10.0.0.2')->assertUnauthorized();
});
