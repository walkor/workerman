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

use Workerman\Worker;
use Workerman\Protocols\Fcgi;

class Request
{
    /**
     * allowed request methods
     *
     * @var array
     */
    const ALLOWED_REQUEST_METHODS = ['GET', 'POST', 'PUT', 'HEAD', 'DELETE'];

    /**
     * allowed FastCGI roles
     *
     * @var array
     */
    const ALLOWED_ROLES = [
        Fcgi::FCGI_RESPONDER,
        Fcgi::FCGI_AUTHORIZER,
        Fcgi::FCGI_FILTER,
    ];

    /**
     * allowed content type
     *
     * @var array
     */
    const ALLOWED_CONTENT_TYPES = [
        self::MIME_URL_ENCODED_FORM_DATA,
        self::MIME_MULTI_PART_FORM_DATA,
        self::MIME_JSON_DATA,
    ];

    /**
     * the MIME type of url encoded form data
     *
     * @var string
     */
    const MIME_URL_ENCODED_FORM_DATA = 'application/x-www-form-urlencoded';

    /**
     * the MIME type of multi part form data
     *
     * @var string
     */
    const MIME_MULTI_PART_FORM_DATA = 'multipart/form-data; boundary=__X_FASTCGI_CLIENT_BOUNDARY__';

    /**
     * the MIME type of json data
     *
     * @var string
     */
    const MIME_JSON_DATA = 'application/json';

    /**
     * FastCGI script to be executed
     *
     * @var string
     */
	public $script = '';

    /**
     * content MIME Type
     *
     * @var string
     */
	public $contentType = self::MIME_URL_ENCODED_FORM_DATA;

    /**
     * content data
     *
     * @var string
     */
	public $content = '';

    /**
     * content length
     *
     * @var int
     */
	public $contentLength = 0;

    /**
     * request uri
     *
     * @var string
     */
	public $requestUri = '';

    /**
     * request method 
     *
     * @var string
     */
	public $requestMethod = 'GET';

    /**
     * query string 
     *
     * @var string
     */
	public $queryString = '';

    /**
     * gateway inteface
     *
     * @var string
     */
	public $gatewayInterface = 'FastCGI/1.0';

    /**
     * server software
     *
     * @var string
     */
	public $serverSoftware = 'FastCGI-Client';

    /**
     * server name 
     *
     * @var string
     */
	public $serverName = 'localhost';

    /**
     * request id 
     *
     * @var int
     */
    public $requestId = 0;

    /**
     * proxy counter for request id 
     *
     * @var int
     */
    static protected $_idCounter = 1;

    /**
     * custom params
     *
     * @var array
     */
	public $customParams = [];

    /**
     * indicates FastCGI server to keep connection alive or not after finishing one request
     *
     * @var boolean
     */
    public $keepAlive = true;

    /**
     * indicates FastCGI server to play the specific role
     *
     * @var string
     */
    public $role = Fcgi::FCGI_RESPONDER;

    /**
     * @brief    __construct    
     *
     * @param    string         $script
     * @param    string|array   $content
     *
     * @return   void
     */
	public function __construct($script = '', $content = '')
	{
		$this->setScript($script);
		$this->setContent($content);
        (self::$_idCounter >= (1 << 16)) && self::$_idCounter = 0;
        $this->requestId = self::$_idCounter++;
	}

    /**
     * @brief    get request id   
     *
     * @return   int
     */
    public function getRequestId() 
    {
        return $this->requestId;
    }

    /**
     * @brief    set the role    
     *
     * @param    int  $role
     *
     * @return   object
     */
    public function setRole($role = Fcgi::FCGI_RESPONDER) 
    {
        if(!is_int($role) || !in_array($role, static::ALLOWED_ROLES))
        {
            $role = Fcgi::FCGI_RESPONDER;
        }

        $this->role = $role;

        return $this;
    }

    /**
     * @brief    get the role    
     *
     * @return   int
     */
	public function getRole() 
    {
        return $this->role;
    }

    /**
     * @brief    set connection alive status   
     *
     * @param    boolean  $status
     *
     * @return   object
     */
    public function setKeepAlive($status = true) 
    {
        $this->keepAlive = !is_bool($status) ? true : $status;

        return $this;
    }

    /**
     * @brief    get connection alive status   
     *
     * @return   boolean
     */
    public function getKeepAlive() 
    {
        return $this->keepAlive;
    }

    /**
     * @brief    get server software  
     *
     * @return   string
     */
    public function getServerSoftware() 
    {
        return $this->serverSoftware;
	}

    /**
     * @brief    set server software  
     *
     * @param    string  $software
     *
     * @return   object
     */
    public function setServerSoftware($software) 
	{
        if(!empty($software) && \is_string($software))
        {
            $this->serverSoftware = $software;
        }

        return $this;
	}

    /**
     * @brief    get server name  
     *
     * @return   string
     */
    public function getServerName() 
    {
        return $this->serverName;
	}

    /**
     * @brief    set server name  
     *
     * @param    string  $name
     *
     * @return   object
     */
    public function setServerName($name) 
	{
        if(!empty($name) && \is_string($name))
        {
            $this->serverName = $name;
        }

        return $this;
	}

    /**
     * @brief    get content type     
     *
     * @return   string
     */
    public function getContentType() 
    {
        return $this->contentType;
	}

    /**
     * @brief    set content type     
     *
     * @param    string  $type
     *
     * @return   object
     */
    public function setContentType($type) 
	{
        if(!\is_string($type) || !in_array($type, static::ALLOWED_CONTENT_TYPES))
        {
            $type = static::MIME_URL_ENCODED_FORM_DATA;
        }

		$this->contentType = $type;

        return $this;
	}

    /**
     * @brief    get content     
     *
     * @return   string
     */
    public function getContent() 
    {
        return $this->content;
	}

    /**
     * @brief    set content     
     *
     * @param    string|array  $content
     *
     * @return   object
     */
    public function setContent($content) 
	{
        if(\is_string($content) || \is_array($content)) 
        {
            $this->content = !\is_string($content) ? http_build_query($content) : $content;
            $this->contentLength = \strlen($this->content);
        }

        return $this;
    }

    /**
     * @brief    get content length   
     *
     * @return   int
     */
    public function getContentLength()
    {
        return $this->contentLength;
	}

    /**
     * @brief    get gateway interface    
     *
     * @return   string
     */
    public function getGatewayInterface() 
    {
        return $this->gatewayInterface;
	}

    /**
     * @brief    set FastCGI script  
     *
     * @param    string  $filename
     *
     * @return   object
     */
    public function setScript($filename) 
	{
        if(!empty($filename) && \is_string($filename))
        {
            $this->script = $filename;
        }

        return $this;
	}

    /**
     * @brief    get FastCGI script  
     *
     * @return   string
     */
    public function getScript() 
    {
        return $this->script;
	}

    /**
     * @brief    set custom params    
     *
     * @param    array  $pair
     *
     * @return   object
     */
	public function setCustomParams($pair) 
	{
        if(!\is_array($pair)) return $this;

        foreach($pair as $k => $v)
        {
            if(!\is_string($v)) continue;
            $this->customParams[$k] = $v;
        }

        return $this;
	}

    /**
     * @brief    append custom params     
     *
     * @param    array  $pair
     *
     * @return   object
     */
    public function appendCustomParams($pair) 
	{
        if(\is_array($pair))
        {
            $this->customParams = \array_merge($this->customParams, $pair);
        }

        return $this;
	}

    /**
     * @brief    reset custom params  
     *
     * @return   object
     */
    public function resetCustomParams() 
    {
        $this->customParams = [];

        return $this;
	}

    /**
     * @brief    set query string     
     *
     * @param    string|array  $string
     *
     * @return   object
     */
    public function setQueryString($data = '')
    {
        if(\is_string($data) || \is_array($data)) 
        {
            $this->queryString = !\is_string($data) ? http_build_query($data) : $data;
        }

        return $this;
    }

    /**
     * @brief    get query string     
     *
     * @return   string
     */
    public function getQueryString()
    {
        return $this->queryString;
    }

    /**
     * @brief    get custom params    
     *
     * @return   array
     */
    public function getCustomParams()
    {
        return $this->customParams;
	}

    /**
     * @brief    get all params  
     *
     * @return   array
     */
    public function getParams()
    {
        return \array_merge($this->customParams, $this->getDefaultParams());
	}

    /**
     * @brief    get default params   
     *
     * @return   array
     */
    public function getDefaultParams()
    {
        return [
            'GATEWAY_INTERFACE' => $this->getGatewayInterface(),
            'SCRIPT_FILENAME'   => $this->getScript(),
            'REQUEST_METHOD'    => $this->getRequestMethod(),
            'REQUEST_URI'       => $this->getRequestUri(),
            'QUERY_STRING'      => $this->getQueryString(),
            'CONTENT_TYPE'      => $this->getContentType(),
            'CONTENT_LENGTH'    => $this->getContentLength(),
            'SERVER_NAME'       => $this->getServerName(),
            'SERVER_SOFTWARE'   => $this->getServerSoftware(),
        ];
    }

    /**
     * @brief    set request method   
     *
     * @param    string  $method
     *
     * @return   object
     */
    public function setRequestMethod($method = 'GET')
    {
        if(!\is_string($method) || !in_array(strtoupper($method), static::ALLOWED_REQUEST_METHODS))
        {
            $method = 'GET';
        }

        $this->requestMethod = strtoupper($method);

        return $this;
    }

    /**
     * @brief    get request method   
     *
     * @return   string
     */
    public function getRequestMethod() 
    {
        return $this->requestMethod;
    }

    /**
     * @brief    get request uri  
     *
     * @return   string
     */
    public function getRequestUri() 
    {
        return $this->requestUri;
	}

    /**
     * @brief    set request uri  
     *
     * @param    string  $uri
     *
     * @return   object
     */
    public function setRequestUri($uri) 
	{
        if(\is_string($uri))
        {
            $this->requestUri = $uri;
        }

        return $this;
	}

}
