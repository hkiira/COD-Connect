<?php

namespace App\Services;

/**
 * We cannot know whether Afra applied the request (timeout, 5xx, unreadable answer).
 * Never retried blindly: a creation may have to be matched with Afra's order list first.
 */
class AfraUncertainException extends AfraException
{
}
