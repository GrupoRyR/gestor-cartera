<?php

namespace App\Modules\Core\Console\Migracion;

use RuntimeException;

/**
 * One row of a legacy CSV cannot be imported. The message is the reason, in
 * Spanish, as the import report shows it (e.g. «campo vacío: email»); the
 * import goes on with the next row.
 */
final class RowRejected extends RuntimeException {}
