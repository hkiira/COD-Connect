<?php

declare(strict_types=1);

namespace Wolf\Speedaf\Menu;
defined( 'ABSPATH' ) || exit; // block direct access.

use Wolf\Speedaf\Core\Providers;
use Wolf\Speedaf\WebApi\TrackSubscribe;
use WP_REST_Server;
class Api extends Providers
{


    public function register() {
        if ( ! function_exists( 'register_rest_route' ) ) {
			// The REST API wasn't integrated into core until 4.4, and we support 4.0+ (for now).
			return false;

		}
  
        add_action('rest_api_init',function(){
            register_rest_route(API_VERSION,TrackSubscribe::getRoute(),[
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [new TrackSubscribe(),'exec'],
                'permission_callback' => '__return_true',

            ]);
        });
     }
    
}
