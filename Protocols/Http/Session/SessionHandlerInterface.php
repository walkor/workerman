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
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Protocols\Http\Session;

use \SessionHandlerInterface as InternalSessionHandlerInterface;

interface SessionHandlerInterface extends InternalSessionHandlerInterface
{

    /**
     * Update sesstion modify time.
     * 
     * @see https://www.php.net/manual/en/class.sessionupdatetimestamphandlerinterface.php
     * 
     * @param string $id Session id.
     * @param string $data Session Data.
     * 
     * @return bool
     */
    public function updateTimestamp($id, $data = "");

}
