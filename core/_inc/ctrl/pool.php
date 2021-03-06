<?php

/**
 * Data Pool Module
 *
 * Author Jerry Shaw <jerry-shaw@live.com>
 * Author 秋水之冰 <27206617@qq.com>
 *
 * Copyright 2017 Jerry Shaw
 * Copyright 2017 秋水之冰
 *
 * This file is part of NervSys.
 *
 * NervSys is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * NervSys is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with NervSys. If not, see <http://www.gnu.org/licenses/>.
 */

namespace core\ctrl;

class pool
{
    //Data package
    public static $data = [];

    //Result data pool
    public static $pool = [];

    //Result data format (json/data)
    public static $format = 'json';

    //Module list
    private static $module = [];

    //Method list
    private static $method = [];

    //Keymap list
    private static $keymap = [];

    //Data Structure
    private static $struct = [];

    /**
     * Start Data Pool Module
     * Only static methods are supported
     */
    public static function start(): void
    {
        //Get date from HTTP Request in CGI Mode
        if ('cli' !== PHP_SAPI) self::$data = ENABLE_GET ? $_REQUEST : $_POST;
        //Parse "cmd" data
        self::parse_cmd();
        //Parse "map" data
        self::parse_map();
        //Set result data format
        if (isset(self::$data['format']) && in_array(self::$data['format'], ['json', 'data'], true)) self::$format = self::$data['format'];
        //Unset "cmd" & "map" & "format" from data package
        unset(self::$data['cmd'], self::$data['map'], self::$data['format']);
        //Merge "$_FILES" into data pool when exist
        if (!empty($_FILES)) self::$data = array_merge(self::$data, $_FILES);
        //Main data incorrect
        if (empty(self::$module) || (empty(self::$method) && empty(self::$data))) return;
        //Build data structure
        self::build_struct();
        //Parse Module & Method list
        foreach (self::$module as $module => $library) {
            //Load Module CFG file for the first time
            $file = realpath(ROOT . '/' . $module . '/_inc/cfg.php');
            if (false !== $file) require $file;
            //Call API
            self::call_api($library);
        }
        unset($module, $library, $file);
    }

    /**
     * Build data structure
     */
    public static function build_struct(): void
    {
        self::$struct = array_keys(self::$data);
    }

    /**
     * "cmd" value parser
     */
    private static function parse_cmd(): void
    {
        //Data incorrect
        if (!isset(self::$data['cmd']) || !is_string(self::$data['cmd']) || false === strpos(self::$data['cmd'], '/')) return;
        //Extract "cmd" values
        $cmd = self::get_list(self::$data['cmd']);
        //Parse "cmd" values
        foreach ($cmd as $item) {
            //Get library module
            $module = self::get_module($item);
            if ('' !== $module) {
                //Module goes here
                //Add module to "self::$module" if not added
                if (!isset(self::$module[$module])) self::$module[$module] = [];
                //Add library to "self::$module" if not added
                if (!in_array($item, self::$module[$module], true)) self::$module[$module][] = $item;
            } else {
                //Method goes here
                //Add to "self::$method" if not added
                if (!in_array($item, self::$method, true)) self::$method[] = $item;
            }
        }
        unset($cmd, $item, $module);
    }

    /**
     * "map" value parser
     */
    private static function parse_map(): void
    {
        //Data incorrect
        if (!isset(self::$data['map']) || !is_string(self::$data['map']) || false === strpos(self::$data['map'], '/') || false === strpos(self::$data['map'], ':')) return;
        //Extract "map" values
        $map = self::get_list(self::$data['map']);
        //Deeply parse map values
        foreach ($map as $value) {
            //Every map value should contain both "/" and ":"
            $position = strpos($value, ':');
            if (false === $position || false === strpos($value, '/')) continue;
            //Extract and get map "from" and "to"
            $map_from = substr($value, 0, $position);
            //Get library module
            $module = self::get_module($map_from);
            if ('' === $module) continue;
            //Declare depth
            $depth = [];
            //Get map keys
            $keys = explode('/', $map_from);
            //Find mapping path
            do {
                $library = implode('/', $keys);
                //Save library existed under the same Module
                if (in_array($library, self::$module[$module], true)) {
                    //Save final method to keymap list with popped keys as mapping depth
                    self::$keymap[$library . '/' . array_pop($depth)][] = ['from' => array_reverse($depth), 'to' => substr($value, $position + 1)];
                    break;
                } else $depth[] = array_pop($keys);
            } while (!empty($keys));
        }
        unset($map, $value, $position, $map_from, $module, $depth, $keys, $library);
    }

    /**
     * Get Method list
     *
     * @param string $library
     *
     * @return array
     */
    private static function get_list(string $library): array
    {
        if (false === strpos($library, '-')) return [$library];
        //Spilt data when multiple modules/methods exist with "-"
        $result = explode('-', $library);
        $result = array_filter($result, 'remove_empty');
        $result = array_unique($result);
        unset($library);
        return $result;
    }

    /**
     * Get library module
     *
     * @param string $library
     *
     * @return string
     */
    private static function get_module(string $library): string
    {
        //Trim "\" and "/"
        $library = trim($library, '\\/');
        //Detect module position
        $module_pos = strpos($library, '/');
        //Module position not detect
        if (false === $module_pos) return '';
        //Get module
        $result = substr($library, 0, $module_pos);
        unset($library, $module_pos);
        return $result;
    }

    /**
     * API Caller
     *
     * @param $library
     */
    private static function call_api(array $library): void
    {
        foreach ($library as $class) {
            //Check root class
            $space = '\\' . str_replace('/', '\\', $class);
            //Skip when class not exist
            if (!class_exists($space)) continue;
            //Get method list from class
            $methods = get_class_methods($space);
            //Call methods
            SECURE_API ? self::secure_call($space, $class, $methods) : self::insecure_call($space, $class, $methods);
        }
        unset($library, $class, $space, $methods);
    }

    /**
     * Method Caller (Secure)
     *
     * @param $space
     * @param $class
     * @param $methods
     */
    private static function secure_call(string $space, string $class, array $methods): void
    {
        //Get API Safe Zone list
        $api_list = isset($space::$api) && is_array($space::$api) ? array_keys($space::$api) : [];
        //Get request api methods, or, all methods in API Safe Zone
        $method_api = !empty(self::$method) ? array_intersect(self::$method, $api_list, $methods) : array_intersect($api_list, $methods);
        //Calling "init" method at the first place without any permission checking
        if (in_array('init', $methods, true) && !in_array('init', $method_api, true)) self::call_method($space, $class, 'init');
        //Go through every method in the api list with API Safe Zone checking
        foreach ($method_api as $method) {
            //Get the intersect list of the data requirement structure
            $intersect = array_intersect(self::$struct, $space::$api[$method]);
            //Get the different list of the data requirement structure
            $difference = array_diff($space::$api[$method], $intersect);
            //Calling api method if the data structure is matched
            if (empty($difference)) {
                try {
                    //Try to call method
                    self::call_method($space, $class, $method);
                } catch (\Throwable $exception) {
                    //Save Exception Message to data pool instead
                    self::$pool[$class . '/' . $method] = 'Secure Call Failed: ' . $exception->getMessage();
                    unset($exception);
                }
            }
        }
        unset($space, $class, $methods, $api_list, $method_api, $method, $intersect, $difference);
    }

    /**
     * Method Caller (Insecure)
     *
     * @param $space
     * @param $class
     * @param $methods
     */
    private static function insecure_call(string $space, string $class, array $methods): void
    {
        //Request api methods is needed in insecure mode
        if (empty(self::$method)) return;
        //Get request api methods
        $method_api = array_intersect(self::$method, $methods);
        //Calling "init" method at the first place without any permission checking
        if (in_array('init', $methods, true) && !in_array('init', $method_api, true)) self::call_method($space, $class, 'init');
        //Calling api method
        foreach ($method_api as $method) {
            try {
                //Try to call method
                self::call_method($space, $class, $method);
            } catch (\Throwable $exception) {
                //Save Exception Message to data pool instead
                self::$pool[$class . '/' . $method] = 'Insecure Call Failed: ' . $exception->getMessage();
                unset($exception);
            }
        }
        unset($space, $class, $methods, $method_api, $method);
    }

    /**
     * Call method and capture result
     *
     * @param string $space
     * @param string $class
     * @param string $method
     */
    private static function call_method(string $space, string $class, string $method): void
    {
        //Get item key
        $item = $class . '/' . $method;
        //Get a reflection object for the class method
        $reflect = new \ReflectionMethod($space, $method);
        //Check the visibility and property of the method
        if (!$reflect->isPublic() || !$reflect->isStatic()) return;
        //Calling method
        $result = $space::$method();
        //No result data
        if (!isset($result)) return;
        //Save result to data pool with original item key
        self::$pool[$item] = &$result;
        //No need to map data
        if (!isset(self::$keymap[$item])) return;
        //Map result data
        foreach (self::$keymap[$item] as $keymap) {
            //Copy result
            $data = $result;
            //Seek to get final data from array
            if (!empty($keymap['from']) && is_array($data)) self::seek_data($keymap['from'], $data);
            //Skip when data is null
            if (is_null($data)) continue;
            //Caution: Data with the same key will be overwritten
            self::$data[$keymap['to']] = $data;
            //Rebuild data structure
            self::build_struct();
        }
        unset($space, $class, $method, $item, $reflect, $result, $keymap, $data);
    }

    /**
     * Seek for deep data
     *
     * @param $keymap
     * @param $data
     */
    private static function seek_data(array $keymap, array &$data): void
    {
        //Loop keymap data
        foreach ($keymap as $key) {
            if (!isset($data[$key])) {
                $data = null;
                return;
            }
            //Switch result data
            $copy = $data[$key];
            $data = $copy;
        }
        unset($keymap, $key, $copy);
    }
}