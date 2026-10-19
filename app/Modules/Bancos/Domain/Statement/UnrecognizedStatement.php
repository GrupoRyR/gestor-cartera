<?php

namespace App\Modules\Bancos\Domain\Statement;

use RuntimeException;

/**
 * The file is not a statement the chosen bank reader understands. The message
 * is Spanish text for the user.
 */
final class UnrecognizedStatement extends RuntimeException {}
