<?php

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Ws;

/**
 * @return array{0: resource, 1: resource, 2: resource}
 */
function requestCacheSockets(): array
{
    $errno = 0;
    $errstr = '';
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        throw new RuntimeException('server socket failed: ' . $errstr);
    }
    $name = stream_socket_get_name($server, false);
    if ($name === false) {
        fclose($server);
        throw new RuntimeException('server name failed');
    }
    $client = stream_socket_client('tcp://' . $name, $errno, $errstr, 2);
    if ($client === false) {
        fclose($server);
        throw new RuntimeException('client socket failed: ' . $errstr);
    }
    $accepted = stream_socket_accept($server, 2);
    if ($accepted === false) {
        fclose($client);
        fclose($server);
        throw new RuntimeException('accept failed');
    }

    return [$client, $accepted, $server];
}

function requestCacheWatch(TcpConnection $connection): void
{
    $connection->context->testErrors = [];
    $connection->errorHandler = static function (Throwable $exception) use ($connection): void {
        $connection->context->testErrors[] = $exception;
    };
}

/**
 * @return array{0: TcpConnection, 1: resource, 2: resource, 3: resource}
 */
function requestCacheConnection(): array
{
    [$client, $accepted, $server] = requestCacheSockets();
    $connection = new TcpConnection(new Select(), $accepted, (string) stream_socket_get_name($accepted, true));
    requestCacheWatch($connection);

    return [$connection, $client, $accepted, $server];
}

/**
 * @return array{0: AsyncTcpConnection, 1: resource, 2: resource, 3: resource}
 */
function requestCacheWsConnection(): array
{
    [$client, $accepted, $server] = requestCacheSockets();
    stream_set_blocking($accepted, false);
    stream_set_read_buffer($accepted, 0);
    $connection = new AsyncTcpConnection('tcp://127.0.0.1:1');
    $connection->protocol = Ws::class;
    Ws::initContext($connection);
    $connection->context->handshakeStep = 2;
    requestCacheWatch($connection);

    return [$connection, $client, $accepted, $server];
}

/**
 * @param resource $client
 * @param resource $accepted
 */
function requestCachePush(TcpConnection $connection, $client, $accepted, string $payload): void
{
    $pending = $payload;
    while ($pending !== '') {
        $written = fwrite($client, $pending);
        if ($written === false || $written === 0) {
            throw new RuntimeException('failed to write the test payload');
        }
        $pending = substr($pending, $written);
    }

    $readAny = false;
    $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline) {
        $read = [$accepted];
        $write = null;
        $except = null;
        $ready = stream_select($read, $write, $except, 0, $readAny ? 100000 : 500000);
        if ($ready === false) {
            throw new RuntimeException('stream_select failed');
        }
        if ($ready !== 1) {
            if ($readAny) {
                return;
            }
            continue;
        }
        $connection->baseRead($accepted, false);
        $errors = $connection->context->testErrors ?? [];
        if ($errors !== []) {
            throw new RuntimeException($errors[0]->getMessage(), 0, $errors[0]);
        }
        $readAny = true;
    }

    if (!$readAny) {
        throw new RuntimeException('timed out waiting for the connection to become readable');
    }
}

/**
 * @param resource $client
 * @param resource $server
 * @param resource|null $accepted
 */
function requestCacheClose(TcpConnection $connection, $client, $server, $accepted = null): void
{
    $connection->destroy();
    if (is_resource($accepted)) {
        fclose($accepted);
    }
    if (is_resource($client)) {
        fclose($client);
    }
    if (is_resource($server)) {
        fclose($server);
    }
}

function requestCacheGet(string $path): string
{
    return "GET $path HTTP/1.1\r\nHost: t\r\n\r\n";
}

function requestCacheSizedGet(int $bytes): string
{
    $prefix = 'GET /';
    $suffix = " HTTP/1.1\r\nHost: t\r\n\r\n";
    $pad = $bytes - strlen($prefix) - strlen($suffix);
    if ($pad <= 0) {
        throw new RuntimeException('requested HTTP message is too small');
    }

    return $prefix . str_repeat('b', $pad) . $suffix;
}

function requestCachePost(string $path, string $body): string
{
    return 'POST ' . $path . " HTTP/1.1\r\nHost: t\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
}

function requestCacheChunked(string $path, string $body, string $trailer = ''): string
{
    $trailerBlock = $trailer === '' ? '' : $trailer . "\r\n";

    return "POST $path HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n"
        . dechex(strlen($body)) . "\r\n{$body}\r\n0\r\n{$trailerBlock}\r\n";
}

function requestCacheWsFrame(string $firstByte, string $payload): string
{
    if (strlen($payload) > 125) {
        throw new RuntimeException('test frame is too large');
    }

    return $firstByte . chr(strlen($payload)) . $payload;
}

it('clears a stale chunked flag before parsing the next request', function () {
    [$connection, $client, $accepted, $server] = requestCacheConnection();
    try {
        $connection->context->chunked = true;
        $request = requestCacheGet('/direct-flag');

        $length = Http::input($request, $connection);

        expect($length)->toBe(strlen($request))
            ->and(isset($connection->context->chunked))->toBeFalse();
    } finally {
        requestCacheClose($connection, $client, $server);
    }
});

it('still marks a chunked request after clearing the previous flag', function () {
    [$connection, $client, $accepted, $server] = requestCacheConnection();
    try {
        $connection->context->chunked = true;
        $request = requestCacheChunked('/direct-chunked', 'ab');

        $length = Http::input($request, $connection);

        expect($length)->toBe(strlen($request))
            ->and($connection->context->chunked)->toBeTrue();
    } finally {
        requestCacheClose($connection, $client, $server);
    }
});

it('does not give another connection a cached decode of the same bytes', function () {
    RequestCacheArrayProtocol::$decodes = 0;
    RequestCacheOtherArrayProtocol::$decodes = 0;
    $packet = "+OK\r\n";
    $httpPacket = requestCacheGet('/issue-1175-http');

    [$arrayConnection, $arrayClient, $arrayAccepted, $arrayServer] = requestCacheConnection();
    [$rawConnection, $rawClient, $rawAccepted, $rawServer] = requestCacheConnection();
    [$otherConnection, $otherClient, $otherAccepted, $otherServer] = requestCacheConnection();
    [$httpConnection, $httpClient, $httpAccepted, $httpServer] = requestCacheConnection();
    [$httpRawConnection, $httpRawClient, $httpRawAccepted, $httpRawServer] = requestCacheConnection();
    try {
        $arrayMessages = [];
        $arrayConnection->protocol = RequestCacheArrayProtocol::class;
        $arrayConnection->onMessage = function (TcpConnection $connection, mixed $data) use (&$arrayMessages): void {
            $arrayMessages[] = $data;
        };
        requestCachePush($arrayConnection, $arrayClient, $arrayAccepted, $packet);
        requestCachePush($arrayConnection, $arrayClient, $arrayAccepted, $packet);

        $rawMessages = [];
        $rawConnection->onMessage = function (TcpConnection $connection, mixed $data) use (&$rawMessages): void {
            $rawMessages[] = $data;
        };
        requestCachePush($rawConnection, $rawClient, $rawAccepted, $packet);

        $otherMessages = [];
        $otherConnection->protocol = RequestCacheOtherArrayProtocol::class;
        $otherConnection->onMessage = function (TcpConnection $connection, mixed $data) use (&$otherMessages): void {
            $otherMessages[] = $data;
        };
        requestCachePush($otherConnection, $otherClient, $otherAccepted, $packet);

        $httpMessages = [];
        $httpConnection->protocol = Http::class;
        $httpConnection->onMessage = function (TcpConnection $connection, Request $request) use (&$httpMessages): void {
            $httpMessages[] = $request->path();
        };
        requestCachePush($httpConnection, $httpClient, $httpAccepted, $httpPacket);

        $httpRawMessages = [];
        $httpRawConnection->onMessage = function (TcpConnection $connection, mixed $data) use (&$httpRawMessages): void {
            $httpRawMessages[] = $data;
        };
        requestCachePush($httpRawConnection, $httpRawClient, $httpRawAccepted, $httpPacket);

        expect($arrayMessages)->toBe([['array', '+OK'], ['array', '+OK']])
            ->and(RequestCacheArrayProtocol::$decodes)->toBe(2)
            ->and($rawMessages)->toBe([$packet])
            ->and($otherMessages)->toBe([['other', '+OK']])
            ->and(RequestCacheOtherArrayProtocol::$decodes)->toBe(1)
            ->and($httpMessages)->toBe(['/issue-1175-http'])
            ->and($httpRawMessages)->toBe([$httpPacket]);
    } finally {
        requestCacheClose($arrayConnection, $arrayClient, $arrayServer);
        requestCacheClose($rawConnection, $rawClient, $rawServer);
        requestCacheClose($otherConnection, $otherClient, $otherServer);
        requestCacheClose($httpConnection, $httpClient, $httpServer);
        requestCacheClose($httpRawConnection, $httpRawClient, $httpRawServer);
    }
});

it('does not cache a decoder result that depends on connection state', function () {
    RequestCacheStatefulProtocol::$decodes = 0;
    [$connection, $client, $accepted, $server] = requestCacheConnection();
    try {
        $messages = [];
        $connection->protocol = RequestCacheStatefulProtocol::class;
        $connection->onMessage = function (TcpConnection $connection, string $data) use (&$messages): void {
            $messages[] = $data;
        };

        requestCachePush($connection, $client, $accepted, "+aa\nZ\n+bb\nZ\n");

        expect($messages)->toBe(['', 'aaZ', '', 'bbZ'])
            ->and(RequestCacheStatefulProtocol::$decodes)->toBe(4);
    } finally {
        requestCacheClose($connection, $client, $server);
    }
});

it('does not serve a repeated packet from the cache when the decoder is stateful', function () {
    RequestCacheStatefulProtocol::$decodes = 0;
    [$connection, $client, $accepted, $server] = requestCacheConnection();
    try {
        $messages = [];
        $connection->protocol = RequestCacheStatefulProtocol::class;
        $connection->onMessage = function (TcpConnection $connection, string $data) use (&$messages): void {
            $messages[] = $data;
        };

        requestCachePush($connection, $client, $accepted, "+cc\nY\n");
        requestCachePush($connection, $client, $accepted, "+dd\n");
        requestCachePush($connection, $client, $accepted, "Y\n");

        expect($messages)->toBe(['', 'ccY', '', 'ddY'])
            ->and(RequestCacheStatefulProtocol::$decodes)->toBe(4);
    } finally {
        requestCacheClose($connection, $client, $server);
    }
});

it('reuses a cached HTTP request without sharing the object between connections', function () {
    RequestCacheHttpA::$decodes = 0;
    $packet = requestCacheGet('/cached-request');
    [$firstConnection, $firstClient, $firstAccepted, $firstServer] = requestCacheConnection();
    [$secondConnection, $secondClient, $secondAccepted, $secondServer] = requestCacheConnection();
    try {
        $delivered = [];
        $firstConnection->protocol = RequestCacheHttpA::class;
        $firstConnection->onMessage = function (TcpConnection $connection, Request $request) use (&$delivered): void {
            if ($delivered === []) {
                $request->properties['marker'] = 'first';
            }
            $delivered[] = $request;
        };
        $secondConnection->protocol = RequestCacheHttpA::class;
        $secondConnection->onMessage = function (TcpConnection $connection, Request $request) use (&$delivered): void {
            $delivered[] = $request;
        };

        requestCachePush($firstConnection, $firstClient, $firstAccepted, $packet);
        requestCachePush($firstConnection, $firstClient, $firstAccepted, $packet);
        requestCachePush($secondConnection, $secondClient, $secondAccepted, $packet);

        expect($delivered)->toHaveCount(3)
            ->and($delivered[0])->not->toBe($delivered[1])
            ->and($delivered[0])->not->toBe($delivered[2])
            ->and($delivered[0]->connection)->toBe($firstConnection)
            ->and($delivered[1]->connection)->toBe($firstConnection)
            ->and($delivered[2]->connection)->toBe($secondConnection)
            ->and($delivered[0]->properties['marker'] ?? null)->toBe('first')
            ->and(isset($delivered[1]->properties['marker']))->toBeFalse()
            ->and(isset($delivered[2]->properties['marker']))->toBeFalse()
            ->and($delivered[2]->path())->toBe('/cached-request')
            ->and(RequestCacheHttpA::$decodes)->toBe(1);
    } finally {
        requestCacheClose($firstConnection, $firstClient, $firstServer);
        requestCacheClose($secondConnection, $secondClient, $secondServer);
    }
});

it('caches an HTTP request only while it stays within the cache size limit', function () {
    RequestCacheHttpA::$decodes = 0;
    $exact = requestCacheSizedGet(TcpConnection::MAX_CACHE_STRING_LENGTH);
    $over = requestCacheSizedGet(TcpConnection::MAX_CACHE_STRING_LENGTH + 1);
    [$connection, $client, $accepted, $server] = requestCacheConnection();
    try {
        $paths = [];
        $connection->protocol = RequestCacheHttpA::class;
        $connection->onMessage = function (TcpConnection $connection, Request $request) use (&$paths): void {
            $paths[] = $request->path();
        };

        requestCachePush($connection, $client, $accepted, $exact);
        requestCachePush($connection, $client, $accepted, $exact);
        requestCachePush($connection, $client, $accepted, $over);
        requestCachePush($connection, $client, $accepted, $over);

        expect(strlen($exact))->toBe(TcpConnection::MAX_CACHE_STRING_LENGTH)
            ->and(strlen($over))->toBe(TcpConnection::MAX_CACHE_STRING_LENGTH + 1)
            ->and($paths)->toHaveCount(4)
            ->and(RequestCacheHttpA::$decodes)->toBe(3);
    } finally {
        requestCacheClose($connection, $client, $server);
    }
});

it('caches an HTTP request that was completed by a later read', function () {
    RequestCacheHttpA::$decodes = 0;
    $packet = requestCacheGet('/completed-later');
    [$connection, $client, $accepted, $server] = requestCacheConnection();
    try {
        $count = 0;
        $connection->protocol = RequestCacheHttpA::class;
        $connection->onMessage = function (TcpConnection $connection, Request $request) use (&$count): void {
            $count++;
            expect($request->path())->toBe('/completed-later')
                ->and($request->connection)->toBe($connection);
        };

        requestCachePush($connection, $client, $accepted, substr($packet, 0, 12));
        expect($count)->toBe(0)
            ->and(RequestCacheHttpA::$decodes)->toBe(0);

        requestCachePush($connection, $client, $accepted, substr($packet, 12));
        requestCachePush($connection, $client, $accepted, $packet);

        expect($count)->toBe(2)
            ->and(RequestCacheHttpA::$decodes)->toBe(1);
    } finally {
        requestCacheClose($connection, $client, $server);
    }
});

it('decodes the next package with the protocol changed inside onMessage', function () {
    [$connection, $client, $accepted, $server] = requestCacheConnection();
    try {
        $messages = [];
        $connection->protocol = RequestCacheLineProtocolA::class;
        $connection->onMessage = function (TcpConnection $connection, string $data) use (&$messages): void {
            $messages[] = $data;
            $connection->protocol = RequestCacheLineProtocolB::class;
        };

        requestCachePush($connection, $client, $accepted, "one\ntwo\n");

        expect($messages)->toBe(['A:one', 'B:two']);
    } finally {
        requestCacheClose($connection, $client, $server);
    }
});

it('stores a cached request on the protocol that decoded it when onMessage switches protocol', function () {
    RequestCacheHttpA::$decodes = 0;
    RequestCacheHttpB::$decodes = 0;
    $packet = requestCacheGet('/switch-mid-buffer');
    [$connection, $client, $accepted, $server] = requestCacheConnection();
    [$protocolBConnection, $protocolBClient, $protocolBAccepted, $protocolBServer] = requestCacheConnection();
    [$protocolAConnection, $protocolAClient, $protocolAAccepted, $protocolAServer] = requestCacheConnection();
    try {
        $delivered = [];
        $connection->protocol = RequestCacheHttpA::class;
        $connection->onMessage = function (TcpConnection $connection, Request $request) use (&$delivered): void {
            $delivered[] = $request;
            $connection->protocol = RequestCacheHttpB::class;
        };
        requestCachePush($connection, $client, $accepted, $packet . $packet);

        $protocolBConnection->protocol = RequestCacheHttpB::class;
        $protocolBConnection->onMessage = function (TcpConnection $connection, Request $request) use (&$delivered): void {
            $delivered[] = $request;
        };
        requestCachePush($protocolBConnection, $protocolBClient, $protocolBAccepted, $packet);

        $protocolAConnection->protocol = RequestCacheHttpA::class;
        $protocolAConnection->onMessage = function (TcpConnection $connection, Request $request) use (&$delivered): void {
            $delivered[] = $request;
        };
        requestCachePush($protocolAConnection, $protocolAClient, $protocolAAccepted, $packet);

        expect($delivered)->toHaveCount(4)
            ->and($delivered[0]->path())->toBe('/switch-mid-buffer')
            ->and($delivered[1]->path())->toBe('/switch-mid-buffer')
            ->and($delivered[0]->connection)->toBe($connection)
            ->and($delivered[1]->connection)->toBe($connection)
            ->and($delivered[2]->connection)->toBe($protocolBConnection)
            ->and($delivered[3]->connection)->toBe($protocolAConnection)
            ->and(RequestCacheHttpA::$decodes)->toBe(1)
            ->and(RequestCacheHttpB::$decodes)->toBe(1);
    } finally {
        requestCacheClose($connection, $client, $server);
        requestCacheClose($protocolBConnection, $protocolBClient, $protocolBServer);
        requestCacheClose($protocolAConnection, $protocolAClient, $protocolAServer);
    }
});

it('evicts cached requests one protocol at a time', function () {
    RequestCacheEvictionHttpA::$decodes = 0;
    RequestCacheEvictionHttpB::$decodes = 0;
    $total = TcpConnection::MAX_CACHE_SIZE + 1;
    $bulk = '';
    for ($i = 0; $i < $total; $i++) {
        $bulk .= requestCacheGet('/evict-a-' . $i);
    }
    $kept = requestCacheGet('/evict-b');

    [$protocolB, $protocolBClient, $protocolBAccepted, $protocolBServer] = requestCacheConnection();
    [$protocolA, $protocolAClient, $protocolAAccepted, $protocolAServer] = requestCacheConnection();
    try {
        $protocolBCount = 0;
        $protocolB->protocol = RequestCacheEvictionHttpB::class;
        $protocolB->onMessage = function (TcpConnection $connection, Request $request) use (&$protocolBCount): void {
            $protocolBCount++;
            expect($request->path())->toBe('/evict-b');
        };
        requestCachePush($protocolB, $protocolBClient, $protocolBAccepted, $kept);

        $protocolACount = 0;
        $protocolA->protocol = RequestCacheEvictionHttpA::class;
        $protocolA->onMessage = function (TcpConnection $connection, Request $request) use (&$protocolACount): void {
            $protocolACount++;
        };
        requestCachePush($protocolA, $protocolAClient, $protocolAAccepted, $bulk);
        expect($protocolACount)->toBe($total)
            ->and(RequestCacheEvictionHttpA::$decodes)->toBe($total);

        requestCachePush($protocolB, $protocolBClient, $protocolBAccepted, $kept);
        requestCachePush($protocolA, $protocolAClient, $protocolAAccepted, requestCacheGet('/evict-a-0'));
        requestCachePush($protocolA, $protocolAClient, $protocolAAccepted, requestCacheGet('/evict-a-' . TcpConnection::MAX_CACHE_SIZE));

        expect($protocolBCount)->toBe(2)
            ->and(RequestCacheEvictionHttpB::$decodes)->toBe(1)
            ->and($protocolACount)->toBe($total + 2)
            ->and(RequestCacheEvictionHttpA::$decodes)->toBe($total + 1);
    } finally {
        requestCacheClose($protocolB, $protocolBClient, $protocolBServer);
        requestCacheClose($protocolA, $protocolAClient, $protocolAServer);
    }
});

it('parses a content-length body after two identical chunked requests in one read', function () {
    [$connection, $client, $accepted, $server] = requestCacheConnection();
    try {
        $bodies = [];
        $connection->protocol = Http::class;
        $connection->onMessage = function (TcpConnection $connection, Request $request) use (&$bodies): void {
            $bodies[] = [$request->path(), $request->rawBody()];
        };
        $chunked = requestCacheChunked('/chunked-same-read', 'foo');

        requestCachePush($connection, $client, $accepted, $chunked . $chunked . requestCachePost('/after-same-read', 'bar'));

        expect($bodies)->toBe([
            ['/chunked-same-read', 'foo'],
            ['/chunked-same-read', 'foo'],
            ['/after-same-read', 'bar'],
        ])->and(isset($connection->context->chunked))->toBeFalse();
    } finally {
        requestCacheClose($connection, $client, $server);
    }
});

it('parses a content-length body when the cached chunked request was in the previous read', function () {
    [$connection, $client, $accepted, $server] = requestCacheConnection();
    try {
        $bodies = [];
        $connection->protocol = Http::class;
        $connection->onMessage = function (TcpConnection $connection, Request $request) use (&$bodies): void {
            $bodies[] = [$request->path(), $request->rawBody()];
        };
        $chunked = requestCacheChunked('/chunked-next-read', 'foo');

        requestCachePush($connection, $client, $accepted, $chunked . $chunked);
        expect($connection->context->chunked)->toBeTrue();

        requestCachePush($connection, $client, $accepted, requestCachePost('/after-next-read', 'bar'));

        expect($bodies)->toBe([
            ['/chunked-next-read', 'foo'],
            ['/chunked-next-read', 'foo'],
            ['/after-next-read', 'bar'],
        ])->and(isset($connection->context->chunked))->toBeFalse();
    } finally {
        requestCacheClose($connection, $client, $server);
    }
});

it('keeps chunk trailers on a request served from the cache', function () {
    RequestCacheHttpA::$decodes = 0;
    [$connection, $client, $accepted, $server] = requestCacheConnection();
    try {
        $trailers = [];
        $connection->protocol = RequestCacheHttpA::class;
        $connection->onMessage = function (TcpConnection $connection, Request $request) use (&$trailers): void {
            $trailers[] = [$request->rawBody(), $request->trailer('X-Trace')];
        };
        $chunked = requestCacheChunked('/chunked-trailer', 'foo', 'X-Trace: abc');

        requestCachePush($connection, $client, $accepted, $chunked);
        requestCachePush($connection, $client, $accepted, $chunked);

        expect($trailers)->toBe([['foo', 'abc'], ['foo', 'abc']])
            ->and(RequestCacheHttpA::$decodes)->toBe(1);
    } finally {
        requestCacheClose($connection, $client, $server);
    }
});

it('parses a content-length body after a cached get arrives with the chunked flag still set', function () {
    [$connection, $client, $accepted, $server] = requestCacheConnection();
    try {
        $seen = [];
        $connection->protocol = Http::class;
        $connection->onMessage = function (TcpConnection $connection, Request $request) use (&$seen): void {
            $seen[] = [$request->path(), $request->rawBody()];
        };
        $get = requestCacheGet('/cached-get-while-chunked');
        $chunked = requestCacheChunked('/flag-left-set', 'foo');

        requestCachePush($connection, $client, $accepted, $get);
        requestCachePush($connection, $client, $accepted, $chunked . $chunked);
        expect($connection->context->chunked)->toBeTrue();

        requestCachePush($connection, $client, $accepted, $get);
        requestCachePush($connection, $client, $accepted, requestCachePost('/after-dirty-flag', 'bar'));

        expect($seen)->toBe([
            ['/cached-get-while-chunked', ''],
            ['/flag-left-set', 'foo'],
            ['/flag-left-set', 'foo'],
            ['/cached-get-while-chunked', ''],
            ['/after-dirty-flag', 'bar'],
        ])->and(isset($connection->context->chunked))->toBeFalse();
    } finally {
        requestCacheClose($connection, $client, $server);
    }
});

it('does not mix websocket fragments when the final frame is repeated', function () {
    [$connection, $client, $accepted, $server] = requestCacheWsConnection();
    try {
        $messages = [];
        $connection->onMessage = function (TcpConnection $connection, string $data) use (&$messages): void {
            $messages[] = $data;
        };
        $first = requestCacheWsFrame(chr(0x01), 'alpha') . requestCacheWsFrame(chr(0x80), 'Z');
        $second = requestCacheWsFrame(chr(0x01), 'beta') . requestCacheWsFrame(chr(0x80), 'Z');
        $single = requestCacheWsFrame(Ws::BINARY_TYPE_BLOB, 'Q');

        requestCachePush($connection, $client, $accepted, $first . $second);
        requestCachePush($connection, $client, $accepted, $single);

        expect($messages)->toBe(['alphaZ', 'betaZ', 'Q']);
    } finally {
        requestCacheClose($connection, $client, $server, $accepted);
    }
});

class RequestCacheHttpA extends Http
{
    public static int $decodes = 0;

    public static function decode(string $buffer, TcpConnection $connection): mixed
    {
        self::$decodes++;

        return parent::decode($buffer, $connection);
    }
}

class RequestCacheHttpB extends Http
{
    public static int $decodes = 0;

    public static function decode(string $buffer, TcpConnection $connection): mixed
    {
        self::$decodes++;

        return parent::decode($buffer, $connection);
    }
}

class RequestCacheEvictionHttpA extends Http
{
    public static int $decodes = 0;

    public static function decode(string $buffer, TcpConnection $connection): mixed
    {
        self::$decodes++;

        return parent::decode($buffer, $connection);
    }
}

class RequestCacheEvictionHttpB extends Http
{
    public static int $decodes = 0;

    public static function decode(string $buffer, TcpConnection $connection): mixed
    {
        self::$decodes++;

        return parent::decode($buffer, $connection);
    }
}

class RequestCacheArrayProtocol
{
    public static int $decodes = 0;

    public static function input(string $buffer, TcpConnection $connection): int
    {
        if ($buffer === '' || !str_ends_with($buffer, "\n")) {
            return 0;
        }

        return strlen($buffer);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function decode(string $buffer, TcpConnection $connection): array
    {
        self::$decodes++;

        return ['array', rtrim($buffer, "\r\n")];
    }
}

class RequestCacheOtherArrayProtocol extends RequestCacheArrayProtocol
{
    public static int $decodes = 0;

    /**
     * @return array{0: string, 1: string}
     */
    public static function decode(string $buffer, TcpConnection $connection): array
    {
        self::$decodes++;

        return ['other', rtrim($buffer, "\r\n")];
    }
}

class RequestCacheStatefulProtocol
{
    public static int $decodes = 0;

    public static function input(string $buffer, TcpConnection $connection): int
    {
        $pos = strpos($buffer, "\n");

        return $pos === false ? 0 : $pos + 1;
    }

    public static function decode(string $buffer, TcpConnection $connection): string
    {
        self::$decodes++;
        $connection->context->pending ??= '';
        $part = rtrim($buffer, "\r\n");
        if (str_starts_with($part, '+')) {
            $connection->context->pending .= substr($part, 1);

            return '';
        }
        $message = $connection->context->pending . $part;
        $connection->context->pending = '';

        return $message;
    }
}

class RequestCacheLineProtocolA
{
    public static function input(string $buffer, TcpConnection $connection): int
    {
        $pos = strpos($buffer, "\n");

        return $pos === false ? 0 : $pos + 1;
    }

    public static function decode(string $buffer, TcpConnection $connection): string
    {
        return 'A:' . rtrim($buffer, "\r\n");
    }
}

class RequestCacheLineProtocolB extends RequestCacheLineProtocolA
{
    public static function decode(string $buffer, TcpConnection $connection): string
    {
        return 'B:' . rtrim($buffer, "\r\n");
    }
}
