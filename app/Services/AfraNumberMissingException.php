<?php

namespace App\Services;

/** Afra accepted the order (it exists there) but did not say its number: find it in the order list. */
class AfraNumberMissingException extends AfraUncertainException
{
}
