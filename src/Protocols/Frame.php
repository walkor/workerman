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

namespace Workerman\Protocols;

use function pack;
use function strlen;
use function substr;
use function unpack;

/**
 * Frame Protocol.
 */
class Frame
{
    /**
     * Check the integrity of the package.
     *
     * @param string $buffer
     * @return int
     */
    public static function input(string $buffer): int
    {
        if (strlen($buffer) < 4) {
            return 0;
        }
        $unpackData = unpack('Ntotal_length', $buffer);
        return $unpackData['total_length'];
    }

    /**
     * Decode.
     *
     * @param string $buffer
     * @return string
     */
    public static function decode(string $buffer): string
    {
        return substr($buffer, 4);
    }

    /**
     * Encode.
     *
     * @param string $data
     * @return string
     */
    public static function encode(string $data): string
    {
        $totalLength = 4 + strlen($data);
        return pack('N', $totalLength) . $data;
    }
}
