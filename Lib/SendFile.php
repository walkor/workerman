<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    linkec<linkec@live.com>
 * @copyright linkec<linkec@live.com>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Lib;

use Workerman\Events\EventInterface;
use Workerman\Worker;
use Exception;

class SendFile
{
    private $connection = null;
    private $handle = null;
    private $offset = 0;
    private $fileSize = 0;
    private $chunkSize = 1048576;
	
	function __construct($connection,$file)
    {
		$this->connection = $connection;
		if(!file_exists($file)){
            return;
		}
		$this->fileSize = filesize($file);
		$this->handle = fopen($file,"rb");
    }
	
    public function _sendFile($socket)
    {
		if($this->offset>$this->fileSize){
			//release handle and remove event
			fclose($this->handle);
			Worker::$globalEvent->del($socket, EventInterface::EV_WRITE);
			return;
		}
		eio_sendfile($socket,$this->handle,$this->offset,$this->chunkSize);
		$this->offset += $this->chunkSize;
		eio_event_loop();
    }
	
    public function send(){
		if(!$this->handle){
			$header = "HTTP/1.1 404 Content Not Found\r\n";
			$header .= "Content-Type: text/html;\r\n";
			$header .= "Server: workerman/" . Worker::VERSION . "\r\n";
			$header .= "\r\n";
			$content = '<h1>404 Content Not Found!</h1>';
			$this->connection->send($header.$content,true);
			return;
		}
        // Default http-code.
        if (!isset(\Workerman\Protocols\HttpCache::$header['Http-Code'])) {
            $header = "HTTP/1.1 200 OK\r\n";
        } else {
            $header = \Workerman\Protocols\HttpCache::$header['Http-Code'] . "\r\n";
            unset(\Workerman\Protocols\HttpCache::$header['Http-Code']);
        }

        // Content-Type
        if (!isset(\Workerman\Protocols\HttpCache::$header['Content-Type'])) {
            $header .= "Content-Type: application/octet-stream;\r\n";
        }

        // header
        $header .= "Server: workerman/" . Worker::VERSION . "\r\n";
		$header .= "Content-Length: ". $this->fileSize .";\r\n";
		$header .= "\r\n";
		$this->connection->send($header,true);
		
		//regist event
		Worker::$globalEvent->add($this->connection->getSocket(), EventInterface::EV_WRITE, array($this, '_sendFile'));
	}
}