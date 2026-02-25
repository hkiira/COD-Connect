<?php

declare(strict_types=1);

namespace Wolf\Speedaf\Speedaf;
defined( 'ABSPATH' ) || exit; // block direct access.
use Automattic\WooCommerce\Admin\Overrides\Order;
use Wolf\Speedaf\Helper\Meta_box;

class CreateOrder extends \Wolf\Speedaf\Api\SpeedafObject
{
   const ORDER_PRE='#';

    private $requiredFileds =  [// required
      'customerCode' => [
           'config' =>  \Wolf\Speedaf\Menu\Settings::SDF_CUSTOMER_CODE,
           'type'   => 'string',
        ], //string 客户单号
        
        'platformSource' => 'woocommerce', //string平台来源 */
        'parcelType'   =>  'PT01', //string快递类型
       
         'deliveryType'    =>  'DE01', //string派送方式
        'payMethod'    => 'PA02', //string支付方式
        'acceptName'        =>   [
            'this' =>  'get_formatted_shipping_full_name',
            'type'   => 'string',
         ], //string收件人姓名
        'acceptMobile'      =>  [
            'this' =>  'get_shipping_phone',
            'type'   => 'string',
         ], // string 收件人手机号
       // 'acceptAddress'      =>  '', // string收件人详细地址
        'acceptCountryCode'      =>   [
            'this' =>  'get_shipping_country',
            'type'   => 'string',
         ], //string收件人国家编码
        'acceptProvinceName'      =>  [
            'this' =>  'get_shipping_city',
            'type'   => 'string',
         ], //string收件人省
        'acceptCityName'      =>  [
            'this' =>  'get_shipping_city',
            'type'   => 'string',
         ], //string收件人市
        'acceptDistrictName'      =>  [
            'this' =>  'get_shipping_state',
            'type'   => 'string',
         ], //string收件人区
        'sendName'      =>  [
            'config' =>  'spf_speedaf_name',
            'type'   => 'string',
         ], //string寄件人姓名
        'sendAddress'      => [
            'config' => ['woocommerce_store_address','woocommerce_store_address_2'],
            'type'   => 'string',

        ], //string寄件人地址
        'sendMobile'      =>  [
            'config' =>  'spf_speedaf_phone',
            'type'   => 'string',
         ],//string寄件人手机号
         'sendCityName'      =>  [
            'config' =>  'woocommerce_store_city',
            'type'   => 'string',
         ], //string寄件人市
        'parcelWeight'      =>  [
            'self' =>  'unitWeight',
            'type'   => 'float',
         ], //decimal包裹重量
        'goodsQTY'      => [
            'self' => 'unitQty'
        ], //Long商品总数
        'pickUpAging'     => 1,
        'piece'            => 1,

    ];


    private $optionalFileds = [
       
        //Optional 
        'customOrderNo'      => [
            'method' => 'get_id',
            'type'   => 'string'
        ],//string //客户单号
        'billCode'      => '',//string //速达非运单号 	根据业务需求，由速达非决定是否传值，默认不传
        'productService'      => '',//string 服务产品 由速达非商务提供专属专线产品，如尼日利亚小包专线
        'smallCode'      => [
            'method' => 'get_id',
            'type'   => 'string'
        ],//string 国内转运单号
        'changeLable'      => '',//int 是否更换面单
        'acceptCompanyName'      => '',//string 收件人公司
        'acceptPostCode'      => [
            'method' => 'get_shipping_postcode',
            'type'   => 'string'
        ],//string 收件客户邮编
        'acceptPhone'      => '',//string 收件人座机
        'acceptEmail'      => '',//string 收件人邮箱
        'acceptCountryName'      => '',//string 收件人国家
        'acceptCitizenId'      =>'',//string 收件人身份ID 土耳其国际件必传（11位数字）
        'acceptProvinceCode'      => '',//string 收件人省编码
        'acceptCityCode'      => [
            'this'  => 'get_shipping_city',
            'type'    => 'string'
        ],//string 收件人区编码
        'acceptDistrictCode'      => '',//string 寄件人公司
        'sendCompanyName'      => '',//string 寄件人公司
        'sendPostCode'      => '',//string 寄件客户邮编
        'sendPhone'      => '',//string 寄件人座机
        'sendMail'      => '',//string 寄件人邮箱
        'sendCountryName'      => '',//string 寄件人国家
        'sendProvinceCode'      => '',//string 寄件人省编码
        'sendCityCode'      => '',//string 寄件人市编码
        'sendDistrictCode'      => '',//string 寄件人区编码
        'parcelLength'      =>[
            'self' => 'unitLength'
        ],//int 包裹长度
        'parcelWidth'      => [
            'self' => 'unitWidth'
        ],//int  包裹宽度
        'parcelHigh'      =>  [
            'self' => 'unitHeight'
        ],//int 包裹高度
        'parcelVolume'    => '',//float 包裹体积
        'parcelValue' => '',//float整个包裹申报货值
        'parcelCurrencyType' => '',//string 包裹申报货值币种
        'shippingFee' => '',//float 运费
        'codFee' =>'',//float 代收货款
        'currencyType' => [
            'method' => 'get_order_currency',
            'type'    => 'string'
        ],//string 货币类型 代收货款币种，仅支持收件国本地币，各国币种类型取国际币种三字码（大写
        'insurePrice' => '',//decimal 保价金额
        'packetCenterCode' => '',//string 集包中心代码
        'threeSectionsCode' => '',//string 三段码 
        'prePickUpTime' => '',//string 预约揽件时间 yyyy-MM-dd HH:mm:ss
        'pickupType' => '',//int 揽收类型 0=否（默认）1=预约揽件2=客户自送
         'pickupName' => '',//string  揽收信息-姓名
         'pickupPhone' => '',// string 揽收信息-电话
        'pickupProvince' => '',//string 揽收信息-省份
        'pickupCity' => '',//string 揽收信息-城市
        'pickupDistrict' => '',//string 揽收信息-区
        'pickupDetailAddress' => '',//string 揽收信息-详细地址
        'remark' => '',//string 备注


    ];

    private $productFileds =  [
        // required
        'sku' => [
            'type' => 'string',
            'method' => 'get_sku',
        ],
        'goodsName' => [
            'type' => 'string',
            'key' => 'name',
        ],
        'goodsQTY' =>  [
            'type' => 'int',
            'key'  => 'qty'
        ],//(int) 
        'goodsValue' =>  [
            'type' => 'float',
            'key'  => 'total'
        ],//(float)
        'goodsType' => 'IT01',
        'blInsure'  =>  0, //	是否保价(int)$itemShipment['blInsure']
        'battery'    => 0, //	是否带电货物(int)$itemShipment['battery']


        'goodsNameDialect' => '', //string 商品名称（本地语言）中文（国际业务必传）
        'hscode' => '', //海关商品编码土耳其必传(string)$itemShipment['hscode']
        'goodsUnitPrice' => [
            'method' => 'get_sale_price',
            'float'
        ] ,//decimal 	商品单价(float)$itemShipment['price']
        'currencyType' => '',//string 传USD，用于关务，仅支持美金，摩洛哥支持MAD(string)$currencyType
        'unit' => '', //string 如：箱，袋，盒
        'goodsRemark' => '', //string 商品备注
        'goodsMaterial' => '',//strings	商品材质
        'goodsWeight' => [
            'method' => 'get_weight',
            'type'    => 'float'
        ], //decimal 商品重量
        'goodsRule' => '',  // string 商品规格

        'goodsLength' => [
            'method' => 'get_length',
            'type'   => 'int'
        ], //	Long 商品长度
        'goodsWidth' => [
            'method' => 'get_width',
            'type'   => 'int'
        ], //Long	商品宽度
        'goodsHigh'  =>[
            'method' => 'get_height',
            'type'   => 'int'
        ] ,  //Long	商品高度
        'goodsVolume' => '', //decimal	商品体积
        'makeCountry' => '', //string	原产国
        'dutyMoney'   => '', //decimal 预付税费
        'salePath'  => '' //string	销售地址

    ];


    private $unitQty = 0;

    private $unitLength = 0;

    private $unitWidth = 0;

    private $unitHeight = 0;

    private $unitWeight = 0.05;


    /**
     * 
     * @var Automattic\WooCommerce\Admin\Overrides\Order
     */
    private $_order;

    /**
     * 
     * @param Order $order 
     * @param array $data 
     * @return void 
     */
    public function __construct(\Automattic\WooCommerce\Admin\Overrides\Order $order ,array $data = [])
    {
        parent::__construct($data);
        $this->_order = $order;
       
        $this->processesItem();
        $this->processesOrder();
    }

    /**
     * 
     * @return $this 
     */
    private function processesItem() {
        $itemList = [];
        foreach ($this->_order->get_items() as  $item ) {
            $order_product = $item->get_product();
          
            $itemData = [];
            foreach($this->productFileds as $id => $type) {
                if(isset($type['key'])) {
                     $val = $item[$type['key']];
                    if(isset($type['type'])) $val = $this->getConvertValue($val,$type['type']);
                     $itemData[$id] = $val;
                }elseif(isset($type['method'])) {
                    $m = $type['method'];
                    $val = $order_product->$m();
                    if(isset($type['type'])) $val =  $this->getConvertValue($val,$type['type']);
                    $itemData[$id] =  $val;
                }else  {
                    $itemData[$id] = $type;
                }
                if(is_array($type)) {
                    switch($id) {
                        case 'goodsQTY':
                            $this->unitQty += $val;break;
                        case 'goodsLength':
                            $this->unitLength = max($val,$this->unitLength);break;
                        case 'goodsWidth':
                            $this->unitWidth = max($val,$this->unitWidth);break;
                        case 'goodsHigh':
                             $this->unitHeight = max($val,$this->unitHeight);break;
                        case 'goodsWeight':
                                if($val) $this->unitWeight += ($item['qty'] * $val);break;

                    }
                }
            }
            $itemList[] = $itemData;
           
        }
        $this->setData('itemList',$itemList);
        return $this;
    }

    private function processesOrder() {
      foreach($this->requiredFileds as $id => $action) {
        $this->handle($id,$action);
      }

      foreach($this->optionalFileds as $id => $action) {
        $this->handle($id,$action);
      }

        $default_location = get_option( 'woocommerce_default_country', '' );
        $location         = wc_format_country_state_string( apply_filters( 'woocommerce_customer_default_location', $default_location ) );
    
        $country          = $location['country'];
        $state            = isset($location['state']) ? $location['state'] : '';
        $this->setData('sendCountryCode',$country);
        $this->setData('sendProvinceName',$state);
        $this->setData('sendDistrictName',$state);
        $this->setData('acceptAddress',$this->acceptAddress());
        //set shipping
       
         /* 'platformSource' => [
            'method' =>  'getCustomerCode',
            'type'   => 'string',
         ], //string平台来源 
         'parcelType'   =>  [
            'method' =>  'getCustomerCode',
            'type'   => 'string',
         ], //string快递类型
         'deliveryType'    =>  [
            'method' =>  'getCustomerCode',
            'type'   => 'string',
         ], //string派送方式
        'transportType'    =>  [
            'method' =>  'getCustomerCode',
            'type'   => 'string',
         ], //string运输方式
        'shipType'    =>  [
            'method' =>  'getCustomerCode',
            'type'   => 'string',
         ], //string订单发货类型
        'payMethod'    =>  [
            'method' =>  'getCustomerCode',
            'type'   => 'string',
         ], //string支付方式
          */
        if($this->_order->get_shipping_country() === $country) {
            $this->setData('transportType','TT01');
            $this->setData('shipType','ST01');
    
        }else {
            //国际发货
            $this->setData('parcelType','PT02');//default
            $this->setData('transportType','TT02');
            $this->setData('shipType','ST02');
        }
        //set COD
        if($this->_order->get_payment_method() === 'cod') {
            $this->setData('codFee',\Wolf\Speedaf\Helper\SpfHelper::resovleOrderTotal($this->_order,true));
            Meta_box::createMeta($this->_order->get_id(),[Meta_box::SPF_ORDER_COD => \Wolf\Speedaf\Helper\SpfHelper::resovleOrderTotal($this->_order) ]);
        }


        if($this->isTRCountry()) {
            throw new \Exception(__('Please Enter acceptCitizenId','speedaf-express-for-woocommerce'));
        }
  
       $this->setData('remark',$this->get_order_notes());
    
   
    }

    /**
     * 
     * @return string 
     */
    private function get_order_notes() {        
        $notes = wc_get_order_notes(['order_id' => $this->_order->get_id()]);
        $notestr =  $this->_order->get_customer_note();
       // var_dump($this->_order);exit;
        foreach($notes as $key =>  $note) {
            $notestr .= ' - '.$note->content;
        }

        return substr($notestr,0,200);
    }
   
    private function isTRCountry() {
        return ($this->getData('acceptCountryCode') === 'TR' && $this->getData('sendCountryCode') !== 'TR') || false;
    }
    private function handle($id,$action,$validate = false) {
        if(is_array($action)) {
          $keys = array_keys($action);
          switch($keys[0]) {
            case 'config':
                if(is_array($action['config'])) {
                    $val = '';
                    foreach($action['config'] as $code) {
                        $val .= get_option($code).' ';
                    }
                }else {
                    $val = get_option($action['config']);
                }
               
                //var_dump($val);exit;
                 if(isset($action['type'])) $val = $this->getConvertValue($val,$action['type']);break;
            case 'method':
                $method = $action['method'];
                $val = $this->_order->$method();
                //setting Order ID
                if($id === 'customOrderNo') $val = self::ORDER_PRE.$val;

                if(isset($action['type'])) $val = $this->getConvertValue($val,$action['type']);break;
            case 'self':
                $method = $action['self'];
                $val = $this->$method;
                if(isset($action['type'])) $val = $this->getConvertValue($val,$action['type']);break;
            case 'this':
                $method = $action['this'];
                $val = $this->$method();
                if(isset($action['type'])) $val = $this->getConvertValue($val,$action['type']);break;
          }

            if($val) {
                
                $this->setData($id,$val);
              
            }
         
           
            
        }else {
            $this->setData($id,$action);
        }
    }

    /**
     * 
     * @param mixed $val 
     * @param mixed $type 
     * @return mixed 
     */
    private function getConvertValue($val,$type) {
        settype($val,$type);

        return $val;
    }


    /**
     * 
     * @return mixed 
     */
    private function get_formatted_shipping_full_name() {
        if( ! $name = trim($this->_order->get_formatted_shipping_full_name()))  $name = $this->_order->get_formatted_billing_full_name();

        return $name;
    }
    /**
     * 
     * @return mixed 
     */
    private function get_shipping_country() {
        if( ! $name = trim($this->_order->get_shipping_country()))  $name = $this->_order->get_billing_country();

        return $name;
    }
    /**
     * 
     * @return mixed 
     */
    private function get_shipping_phone() {
        if( ! $name = trim($this->_order->get_shipping_phone()))  $name = $this->_order->get_billing_phone();

        return $name;
    }
    /**
     * 
     * @return mixed 
     */
    private function get_shipping_city() {
        if( ! $name = trim($this->_order->get_shipping_city()))  $name = $this->_order->get_billing_city();

        return $name;
    }
    /**
     * 
     * @return mixed 
     */
    private function get_shipping_state() {
        if( ! $name = trim($this->_order->get_shipping_state()))  $name = $this->_order->get_billing_state();

        return $name;
    }

    private function acceptAddress() {
        if(trim($this->_order->get_shipping_address_1())) return $this->_order->get_shipping_address_1() . ' ' . $this->_order->get_shipping_address_2();

        return $this->_order->get_formatted_billing_address();
    }
   

       

}
