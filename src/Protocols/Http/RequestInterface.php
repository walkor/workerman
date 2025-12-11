<?php

declare(strict_types=1);

namespace Workerman\Protocols\Http;

/**
 * Request interface
 */
interface RequestInterface
{
    /**
     * Destroy.
     *
     * @return void
     */
    public function destroy();
}
