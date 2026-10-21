<?php

namespace App\Modules\Core\Console\Migracion;

use RuntimeException;

/**
 * One row of a legacy CSV has nothing to import: it is already in the app or
 * another command imports it. The message is the reason, in Spanish, as the
 * import report groups the skipped rows (e.g. «ya importado»). Nothing the
 * row wrote before is kept.
 */
final class RowSkipped extends RuntimeException {}
