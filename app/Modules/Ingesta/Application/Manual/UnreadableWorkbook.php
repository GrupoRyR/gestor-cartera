<?php

namespace App\Modules\Ingesta\Application\Manual;

use RuntimeException;

/**
 * The uploaded Excel cannot become a preview: not a workbook, no header
 * row or a required column missing. The message is Spanish, for the user.
 */
final class UnreadableWorkbook extends RuntimeException {}
