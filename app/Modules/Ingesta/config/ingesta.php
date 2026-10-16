<?php

// Ingesta settings, merged by IngestaServiceProvider under the "ingesta" key:
// read them with config('ingesta.<setting>'). Only this file calls env().
return [

    // Robot signing keys as comma-separated "key-id:secret" pairs. The module
    // parses them; the secrets live only in .env.
    'hmac_keys' => env('INGESTA_HMAC_KEYS', ''),

    // Maximum clock difference, in seconds, accepted on a signed request.
    'hmac_max_skew' => (int) env('INGESTA_HMAC_MAX_SKEW', 300),

    // Largest robot request body, in bytes, as sent (gzipped or not); a
    // bigger one answers 413. PHP's post_max_size must be at least this.
    'max_request_bytes' => 20 * 1024 * 1024,

    // Largest body once gunzipped, in bytes; a bigger one answers 422.
    'max_payload_bytes' => 60 * 1024 * 1024,

    // Most rows one batch may carry. A cartera of a company has a few
    // thousand installments; this only stops a runaway extraction.
    'max_rows' => 200000,

    // Time (America/Bogota) by which the robot must have published the day's load.
    'alert_deadline' => env('INGESTA_ALERT_DEADLINE', '07:00'),

    // Who receives the missing-load alert (IT and the receivables manager),
    // from a comma-separated list.
    'alert_recipients' => array_values(array_filter(
        array_map(trim(...), explode(',', (string) env('INGESTA_ALERT_RECIPIENTS', ''))),
        fn (string $recipient): bool => $recipient !== '',
    )),

];
