<?php

namespace KD2;

abstract class Singleton
{
    private static $instances = array();
 
    /**
     * Disables instanciation
     */
    protected function __construct()
    {
    }
 
    /**
     * Disables cloning
     */
    final public function __clone()
    {
        throw new \LogicException('Cloning disabled: class is a singleton');
    }

    /**
     * Returns object instance
     */
    final public static function getInstance()
    {
        $c = get_called_class();
 
        if(!isset(self::$instances[$c]))
        {
            self::$instances[$c] = new $c;
        }
 
        return self::$instances[$c];
    }

    final public static function deleteInstance()
    {
        $c = get_called_class();

        unset(self::$instances[$c]);

        return true;
    }
}
