<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| Off for every path (audit S-21). No browser calls the API: the robot signs
| its batches with HMAC and Treasury sends a bearer token, and the app's own
| pages call it from the same origin. The framework's default would answer
| "Access-Control-Allow-Origin: *" on api/*. The rest of the options keep the
| framework's values, merged under this file.
|
*/

return [

    'paths' => [],

];
