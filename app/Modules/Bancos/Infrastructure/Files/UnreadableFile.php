<?php

namespace App\Modules\Bancos\Infrastructure\Files;

use RuntimeException;

/**
 * The file cannot be opened as a spreadsheet or a CSV. The message is Spanish
 * text for the user.
 */
final class UnreadableFile extends RuntimeException {}
