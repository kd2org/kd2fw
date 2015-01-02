<?php
/*
    Copyleft (C) 2005-2015 BohwaZ <http://bohwaz.net/>

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as
    published by the Free Software Foundation, version 3 of the
    License.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */


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
