<?php

// Soportes settings, merged by SoportesServiceProvider under the "soportes" key:
// read them with config('soportes.<setting>'). Only this file calls env().
return [

    // Private disk the receipt files are stored on. "local" is
    // storage/app/private: never under public/, served only by the
    // authorized download action.
    'disk' => 'local',

    // Folder on that disk. Files go to <directory>/<company>/<yyyy>/<mm>/<uuid>.<ext>,
    // under a generated name, never the one the user uploaded.
    'directory' => 'soportes',

    // Files per receipt (at least one).
    'max_files' => 3,

    // Largest file accepted, in kilobytes (10 MB).
    'max_file_kilobytes' => 10240,

    // Accepted files. The extension and the real content type (sniffed from
    // the bytes, not the name) must both be in these lists.
    'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
    'allowed_mimetypes' => ['application/pdf', 'image/jpeg', 'image/png'],

];
