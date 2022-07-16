<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    爬山虎<blogdaren@163.com>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Workerman\Protocols\FastCGI;

class Response
{
    /**
     * success status
     *
     * @var int
     */
    const STATUS_OK = 200;

    /**
     * invalid status
     *
     * @var int
     */
    const STATUS_INVALID = -1;

    /**
     * the request id from response
     *
     * @var int
     */
    protected $_requestId;

    /**
     * the stdout from response
     *
     * @var string
     */
    protected $_stdout = '';

    /**
     * the stderr from response 
     *
     * @var string
     */
    protected $_stderr = '';

    /**
     * the origin header from response 
     *
     * @var string
     */
    protected $_header = '';

    /**
     * the origin body from response 
     *
     * @var string
     */
    protected $_body = '';

    /**
     * @brief    __construct    
     *
     * @param    int    $request_id
     *
     * @return   void
     */
    public function __construct($request_id = 0)
    {
        $this->setRequestId($request_id);
    }

    /**
     * @brief    set request id
     *
     * @return   int
     */
    public function setRequestId($id = 0)
    {
        $this->_requestId = (\is_int($id) && $id > 0) ? $id : -1;

        return $this;
    }

    /**
     * @brief    set stdout  
     *
     * @param    string  $stdout
     *
     * @return   object
     */
    public function setStdout($stdout = '')
    {
        if(\is_string($stdout)) 
        {
            $this->_stdout = $stdout;
        }

        return $this;
    }

    /**
     * @brief    get stdout  
     *
     * @return   string
     */
    public function getStdout()
    {
        return $this->_stdout;
    }

    /**
     * @brief    set stderr  
     *
     * @param    string  $stderr
     *
     * @return   object
     */
    public function setStderr($stderr = '')
    {
        if(\is_string($stderr)) 
        {
            $this->_stderr = $stderr;
        }

        return $this;
    }

    /**
     * @brief    get stderr
     *
     * @return   void
     */
    public function getStderr()
    {
        return $this->_stderr;
    }

    /**
     * @brief    get header   
     *
     * @return   string
     */
    public function getHeader()
    {
        return $this->_header;
    }

    /**
     * @brief    get body
     *
     * @return   string
     */
    public function getBody()
    {
        return $this->_body;
    }

    /**
     * @brief    get request id
     *
     * @return   int
     */
    public function getRequestId()
    {
        return $this->_requestId;
    }

    /**
     * @brief    format response output
     *
     * @return   array
     */
    public function formatOutput()
    {
        $status = static::STATUS_INVALID;
        $header = [];
        $body = '';
        $crlf_pos = \strpos($this->getStdout(), "\r\n\r\n");

        if(false !== $crlf_pos) 
        {
            $status = static::STATUS_OK;
            $head = \substr($this->getStdout(), 0, $crlf_pos);
            $body = \substr($this->getStdout(), $crlf_pos + 4);
            $this->_header = \substr($this->getStdout(), 0, $crlf_pos + 4);
            $this->_body = $body;
            $header_lines = \explode(PHP_EOL, $head);

            foreach($header_lines as $line) 
            {
                if(preg_match('/([\w-]+):\s*(.*)$/', $line, $matches)) 
                {
                    $name  = \trim($matches[1]);
                    $value = \trim($matches[2]);

                    if('status' === strtolower($name)) 
                    {
                        $pos = strpos($value, ' ') ;
                        $status = false !== $pos ? \substr($value, 0, $pos) : static::STATUS_OK;
                        continue;
                    }

                    if(!array_key_exists($name, $header)) 
                    {
                        $header[$name] = $value;
                        continue;
                    } 

                    !\is_array($header[$name]) && $header[$name] = [$header[$name]];
                    $header[$name][] = $value;
                }
            }
        }

        $output = [
            'requestId' => $this->getRequestId(),
            'status'    => $status,
            'stderr'    => $this->getStderr(),
            'header'    => $header,
            'body'      => $body,
        ];

        return $output;
    }
}

