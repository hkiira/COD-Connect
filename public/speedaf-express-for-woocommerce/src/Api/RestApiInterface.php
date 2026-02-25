<?php

declare(strict_types=1);

namespace Wolf\Speedaf\Api;
defined( 'ABSPATH' ) || exit; // block direct access.

interface RestApiInterface
{
    public function exec( \WP_REST_Request $request);

    public static function getRoute();
   
}
