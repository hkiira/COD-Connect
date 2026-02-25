<?php

declare(strict_types=1);

namespace Wolf\Speedaf\Api;
defined( 'ABSPATH' ) || exit; // block direct access.

abstract class SpeedafObject implements \ArrayAccess
{
    protected $_data = [];


    public function __construct(array $data = [])
    {
        $this->_data = $data;
    }


     /**
     * Add data to the object.
     *
     * Retains previous data in the object.
     *
     * @param array $arr
     * @return $this
     */
    public function addData(array $arr)
    {
        foreach ($arr as $index => $value) {
            $this->setData($index, $value);
        }
        return $this;
    }

    /**
     * Overwrite data in the object.
     *
     * The $key parameter can be string or array.
     * If $key is string, the attribute value will be overwritten by $value
     *
     * If $key is an array, it will overwrite all the data in the object.
     *
     * @param string|array $key
     * @param mixed $value
     * @return $this
     */
    public function setData($key, $value = null)
    {
        if ($key === (array)$key) {
            $this->_data = $key;
        } else {
            $this->_data[$key] = $value;
        }
        return $this;
    }

    /**
     * Unset data from the object.
     *
     * @param null|string|array $key
     * @return $this
     */
    public function unsetData($key = null)
    {
        if ($key === null) {
            $this->setData([]);
        } elseif (is_string($key)) {
            if (isset($this->_data[$key]) || array_key_exists($key, $this->_data)) {
                unset($this->_data[$key]);
            }
        } elseif ($key === (array)$key) {
            foreach ($key as $element) {
                $this->unsetData($element);
            }
        }
        return $this;
    }

    /**
     * Object data getter
     *
     * If $key is not defined will return all the data as an array.
     * Otherwise it will return value of the element specified by $key.
     * It is possible to use keys like a/b/c for access nested array data
     *
     * If $index is specified it will assume that attribute data is an array
     * and retrieve corresponding member. If data is the string - it will be explode
     * by new line character and converted to array.
     *
     * @param string $key
     * @param string|int $index
     * @return mixed
     */
    public function getData($key = '')
    {
        if ('' === $key) {
            return $this->_data;
        }

        $data = $this->_getData($key);
        
        return $data;
    }

    /**
     * Get value from _data array without parse key
     *
     * @param   string $key
     * @return  mixed
     */
    protected function _getData($key)
    {
        if (isset($this->_data[$key])) {
            return $this->_data[$key];
        }
        return null;
    }


    #[\ReturnTypeWillChange]
 public function offsetExists( $offset) {
    return isset($this->_data[$offset]) || array_key_exists($offset, $this->_data);
  }

  #[\ReturnTypeWillChange]
 public function offsetGet( $offset) { 
    if (isset($this->_data[$offset])) {
        return $this->_data[$offset];
    }
    return null;
 }
 #[\ReturnTypeWillChange]
 public function offsetSet( $offset, $value) {
    $this->_data[$offset] = $value;
    
  }

  #[\ReturnTypeWillChange]
 public function offsetUnset( $offset): void { 
    unset($this->_data[$offset]);
 }
    
}
