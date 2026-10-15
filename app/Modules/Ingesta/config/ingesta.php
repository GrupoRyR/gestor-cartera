<?php

// Ingesta settings, merged by IngestaServiceProvider under the "ingesta" key:
// read them with config('ingesta.<setting>'). Only this file calls env().
return [

    // Robot signing keys as comma-separated "key-id:secret" pairs. The module
    // parses them; the secrets live only in .env.
    'hmac_keys' => env('INGESTA_HMAC_KEYS', ''),

    // Maximum clock difference, in seconds, accepted on a signed request.
    'hmac_max_skew' => (int) env('INGESTA_HMAC_MAX_SKEW', 300),

    // Time (America/Bogota) by which the robot must have published the day's load.
    'alert_deadline' => env('INGESTA_ALERT_DEADLINE', '07:00'),

    // Who receives the missing-load alert (IT and the receivables manager),
    // from a comma-separated list.
    'alert_recipients' => array_values(array_filter(
        array_map(trim(...), explode(',', (string) env('INGESTA_ALERT_RECIPIENTS', ''))),
        fn (string $recipient): bool => $recipient !== '',
    )),

];
