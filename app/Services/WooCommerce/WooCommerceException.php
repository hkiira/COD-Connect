<?php

namespace App\Services\WooCommerce;

use RuntimeException;

/** A call to a WooCommerce store failed (unreachable, refused keys, API error). The message is safe to show. */
class WooCommerceException extends RuntimeException
{
}
