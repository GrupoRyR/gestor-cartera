<?php

namespace App\Modules\Core\Contracts\Exceptions;

use RuntimeException;

/**
 * Base of every exception a Core contract declares, so callers can catch
 * them as a family. Messages are for logs and developers (English); modules
 * show their own Spanish text to users.
 */
abstract class ContractException extends RuntimeException {}
