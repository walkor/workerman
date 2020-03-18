<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 *
 * @link      http://www.workerman.net/
 */

// Display errors.
ini_set('display_errors', 'on');
// Reporting all.
error_reporting(E_ALL);
// JIT is not stable, temporarily disabled.
ini_set('pcre.jit', 0);

// For onError callback.
const WORKERMAN_CONNECT_FAIL = 1;
// For onError callback.
const WORKERMAN_SEND_FAIL = 2;

// Define OS Type
const OS_TYPE_LINUX   = 'linux';
const OS_TYPE_WINDOWS = 'windows';

// Compatible with php7
if (!class_exists('Error')) {
    class Error extends Exception
    {
    }
}

if (!interface_exists('SessionHandlerInterface')) {
    interface SessionHandlerInterface {
        public function close();
        public function destroy($session_id);
        public function gc($maxlifetime);
        public function open($save_path ,$session_name);
        public function read($session_id);
        public function write($session_id , $session_data);
    }
}
