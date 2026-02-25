<?php

declare(strict_types=1);

namespace Wolf\Speedaf\Core;
defined( 'ABSPATH' ) || exit; // block direct access.

use Wolf\Speedaf\Container;
use Wolf\Speedaf\Api\RegisterInterface;
use Wolf\Speedaf\Controller\View;
abstract class Providers implements RegisterInterface
{
    protected $view;
    /**
     * 
     * @var Container
     */
    protected $_service;

    protected $_template ='';

    protected $_data = [];
    /**
     * 
     * @param Container $service 
     * @return void 
     */
    public function __construct(Container $service)
    {
        $this->_service  = $service;
        $this->view = View::initInstance()->setTemplate($this->_template)->setData($this->_data);
       
    }

    /**
     * 
     * @return mixed 
     */
   final  public function  getView() {
        $this->view->reset();
       return $this->view->setTemplate($this->_template)->setData($this->_data);
   }
 }
