<?php

// Ingesta settings, merged by IngestaServiceProvider under the "ingesta" key:
// read them with config('ingesta.<setting>'). Only this file calls env().
return [

    // Robot signing keys as comma-separated "key-id:secret" pairs. The module
    // parses them; the secrets live only in .env.
    'hmac_keys' => env('INGESTA_HMAC_KEYS', ''),

    // Maximum clock difference, in seconds, accepted on a signed request.
    'hmac_max_skew' => (int) env('INGESTA_HMAC_MAX_SKEW', 300),

    // The three caps below are sized to the real batches (audit S-19): the
    // largest one has 1,458 rows (ventas SGX) and the masters at most
    // 6,178. 20,000 rows of the widest dataset, cartera with 38
    // columns, measured about 15.8 MB of JSON, 1.8 MB gzipped and 86 MB of
    // peak memory to decode.

    // Largest robot request body, in bytes, as sent (gzipped or not); a
    // bigger one answers 413. Without gzip it fits about 5,000 cartera rows.
    // PHP's post_max_size must be at least this.
    'max_request_bytes' => 4 * 1024 * 1024,

    // Largest body once gunzipped, in bytes; a bigger one answers 422.
    'max_payload_bytes' => 16 * 1024 * 1024,

    // Most rows one batch may carry, from the robot (checked before the rows
    // are read) and in a manual cartera Excel (CarteraWorkbookReader). It
    // only stops a runaway extraction.
    'max_rows' => 20000,

    // Days a manual cartera upload may wait on the local disk for confirm or
    // discard; ingesta:limpiar-cargas-manuales deletes older ones every day.
    'manual_upload_ttl_days' => 7,

    // Time (America/Bogota) by which the robot must have published the day's load.
    'alert_deadline' => env('INGESTA_ALERT_DEADLINE', '07:00'),

    // Companies whose robot cartera load is expected every day: the history
    // warns, and ingesta:verificar-carga mails, when it is missing after the
    // deadline. Only SGX: the robot reads the SEGUREX HANA schema only, and
    // SDC loads its cartera by manual Excel until its SAP schema is configured.
    'alert_companies' => ['SGX'],

    // Who receives the missing-load alert (IT and the receivables manager),
    // from a comma-separated list.
    'alert_recipients' => array_values(array_filter(
        array_map(trim(...), explode(',', (string) env('INGESTA_ALERT_RECIPIENTS', ''))),
        fn (string $recipient): bool => $recipient !== '',
    )),

];
