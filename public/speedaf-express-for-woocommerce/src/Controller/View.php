<?php

declare(strict_types=1);

namespace Wolf\Speedaf\Controller;
defined( 'ABSPATH' ) || exit; // block direct access.
class View
{
    protected $_template;

    private static $_instance;

    protected $_data = [];

    protected $display_mode = false;

    /**
     * 
     * @param array $data 
     * @return void 
     */
    public function __construct(array $data)
    {
        if(isset($data['template']))  $this->_template = $data['template'];

        unset($data['template']);
        $this->_data = $data;
    }

    /**
     * 
     * @return mixed 
     */
    public function getTemplate() {
        return $this->_tempate;
    }

    public function setData(array $data) {
        if(isset($data['template'])){
             $this->setTemplate($data['template']);
             unset($data['template']);
        }
        $this->_data = $data;
        return $this;
    }

    /**
     * 
     * @param string $tempate 
     * @return $this 
     */
    public function setTemplate(string $tempate) {
        $this->_tempate = $tempate;
        return $this;
    }

    /**
     * 
     * @param array $data 
     * @return mixed 
     */

    public static function initInstance(array $data = []) {
        
        if(self::$_instance === null)  self::$_instance = new self($data);
        return self::$_instance;
    }

    /**
     * 
     * @param mixed $mode 
     * @return $this 
     */

    public function setDisplayMode($mode) {
        $this->display_mode = $mode;
        return $this;
    }

    /**
     * 
     * @return string|false 
     */
    public function render() {
        if(!$this->_tempate)  return '';
        ob_start();
       extract($this->_data,EXTR_SKIP);
       include SDF_PLUGIN_DIR.'templates'.DIRECTORY_SEPARATOR.$this->_tempate;
       $output = ob_get_clean();
 
      // if($this->display_mode) return $output;

       return  $output;
     

    }

    public function reset() {
        $this->_tempate = '';
        $this->_data    = [];
        $this->display_mode = false;
    }

    public function __destruct()
    {
        
       $this->reset();
    }
}
