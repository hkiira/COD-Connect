<?php

namespace App\Services;

use RuntimeException;

/** Afra refused the request (or it could not be built): nothing was applied on Afra's side. */
class AfraException extends RuntimeException
{
}
