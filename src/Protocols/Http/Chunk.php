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

declare(strict_types=1);

namespace Workerman\Protocols\Http;

use function dechex;
use function strlen;

/**
 * Class Chunk
 * @package Workerman\Protocols\Http
 */
class Chunk
{
    /**
     * Chunk buffer.
     *
     * @var string
     */
    protected string $buffer;

    /**
     * Chunk constructor.
     *
     * @param string $buffer
     */
    public function __construct(string $buffer)
    {
        $this->buffer = $buffer;
    }

    /**
     * __toString
     *
     * @return string
     */
    public function __toString()
    {
        return dechex(strlen($this->buffer)) . "\r\n$this->buffer\r\n";
    }
}