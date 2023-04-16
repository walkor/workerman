<?php

use Workerman\Connection\UdpConnection;
use Symfony\Component\Process\PhpProcess;

$remoteAddress = '[::1]:12345';
$process = new PhpProcess(<<<PHP
<?php
\$socketServer = stream_socket_server("udp://$remoteAddress", \$errno, \$errstr, STREAM_SERVER_BIND);
do{
    \$data = stream_socket_recvfrom(\$socketServer, 3);
}while(\$data !== false && \$data !== 'bye');
PHP
);
$process->start();

it('tests ' . UdpConnection::class, function () use ($remoteAddress) {

    $socketClient = stream_socket_client("udp://$remoteAddress");
    $udpConnection = new UdpConnection($socketClient, $remoteAddress);
    $udpConnection->protocol = \Workerman\Protocols\Text::class;
    expect($udpConnection->send('foo'))->toBeTrue();

    expect($udpConnection->getRemoteIp())->toBe('::1');
    expect($udpConnection->getRemotePort())->toBe(12345);
    expect($udpConnection->getRemoteAddress())->toBe($remoteAddress);
    expect($udpConnection->getLocalIp())->toBeIn(['::1', '[::1]', '127.0.0.1']);
    expect($udpConnection->getLocalPort())->toBeInt();

    expect(json_encode($udpConnection))->toBeJson()
        ->toContain('transport')
        ->toContain('getRemoteIp')
        ->toContain('remotePort')
        ->toContain('getRemoteAddress')
        ->toContain('getLocalIp')
        ->toContain('getLocalPort')
        ->toContain('isIpV4')
        ->toContain('isIpV6');

    $udpConnection->close('bye');
    if (is_resource($socketClient)) {
        fclose($socketClient);
    }
});