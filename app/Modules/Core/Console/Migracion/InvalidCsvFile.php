<?php

namespace App\Modules\Core\Console\Migracion;

use RuntimeException;

/**
 * A legacy CSV file cannot be read as a whole: it is missing, empty, not
 * UTF-8 or lacks required columns. The message, in Spanish, is shown to
 * whoever runs the migration; the command stops before importing anything.
 */
final class InvalidCsvFile extends RuntimeException {}
