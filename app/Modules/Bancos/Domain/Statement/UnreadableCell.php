<?php

namespace App\Modules\Bancos\Domain\Statement;

use RuntimeException;

/**
 * A cell that has content but not the kind a column needs (an amount that is
 * not a number, or one that could be read two ways).
 */
final class UnreadableCell extends RuntimeException {}
