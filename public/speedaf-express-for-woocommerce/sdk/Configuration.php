<?php
defined( 'ABSPATH' ) || exit; // block direct access.
return 
[
	// The secretkey and the customer code AppCode are provided by Su Da Fei
	'app_code' => API_DEBUG ? 'TT660085' : '880056',
    'secret_key' => API_DEBUG ? 'qz4CWUEC' : '5oQpOLF7',
    'base_path' => API_DEBUG ? 'http://8.214.27.92:8480/' : 'https://apis.speedaf.com/', // kinldy replace this with the LIVE PATH like 'api.speedaf.com'
    'vip_path'   => API_DEBUG ? 'http://8.214.27.92:8093/' : 'https://csp.speedaf.com/',


    /*
    Don't temper with the below settings 
    except you know what you are doing
	*/

	'sorting_code_by_waybill_path'   => '/open-api/network/threeSectionsCode/getByBillCode',
    'sorting_code_by_address_path'   =>  '/open-api/network/threeSectionsCode/getByAddress',
    'create_order_path'              => '/open-api/express/order/createOrder',
    'batch_create_order_path'        => '/open-api/express/order/batchCreateOrders',
    'cancel_order_path'              => '/open-api/express/order/cancelOrder',
    'track_path'                     => '/open-api/express/track/query',
    'print_path'                     => '/open-api/express/order/print',
    'track_subscribe'                => 'open-api/express/track/subscribe',
    'get_customer_code'              => 'open-api/express/order/getCustomerCode',
    'update_order_path'               => '/open-api/express/order/updateOrder',
   // 'third_party_track' => '/open-api/express/track/arrivePush',
    'labelType' => [
        1 => __('triplicate form （76×203）','speedaf-express-for-woocommerce'),
        2 => __('Double sheet without logo （10×18）','speedaf-express-for-woocommerce'),
        3 => __('Double sheet with logo （10×18）','speedaf-express-for-woocommerce'),
        5 => __('Double sheet with logo （10×15）','speedaf-express-for-woocommerce'),
      //  6 => __('Double sheet with logo （10×15）','speedaf-express-for-woocommerce'),

    ],
    'allowedCurrencies' => [
        'CN' =>	'China',
        'GH' =>	'Ghana',
        'UG' =>	'Uganda',
        'KE' =>	'Kenya',
        'NG' =>	'Nigeria',
        'MA' =>	'Morocco',
        'EG' =>	'Egypt',
        'BD' =>	'Bangladesh',
        'PK' =>	'Pakistan',
        'SA' =>	'Saudi Arabia',
        'AE' =>	'Arab Emirates',
        'TR' =>	'Turkey',
    ],
	
];

?>