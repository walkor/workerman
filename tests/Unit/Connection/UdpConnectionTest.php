<?php

use Workerman\Connection\UdpConnection;
use Symfony\Component\Process\PhpProcess;
use Workerman\Protocols\Text;

$remoteAddress = '127.0.0.1:8082';
$process = null;
beforeAll(function () use ($remoteAddress, &$process) {
    $process = new PhpProcess(<<<PHP
        <?php
        \$socketServer = stream_socket_server("udp://$remoteAddress", \$errno, \$errstr, STREAM_SERVER_BIND);
        do{
            \$data = stream_socket_recvfrom(\$socketServer, 3);
        }while(\$data !== false && \$data !== 'bye');
    PHP
    );
    $process->start();
    usleep(250000);
});

afterAll(function () use (&$process) {
    $process->stop();
});

it('tests ' . UdpConnection::class, function () use ($remoteAddress) {

    $socketClient = stream_socket_client("udp://$remoteAddress");
    $udpConnection = new UdpConnection($socketClient, $remoteAddress);
    $udpConnection->protocol = Text::class;
    expect($udpConnection->send('foo'))->toBeTrue()
        ->and($udpConnection->getRemoteIp())->toBe('127.0.0.1')
        ->and($udpConnection->getRemotePort())->toBe(8082)
        ->and($udpConnection->getRemoteAddress())->toBe($remoteAddress)
        ->and($udpConnection->getLocalIp())->toBeIn(['::1', '[::1]', '127.0.0.1'])
        ->and($udpConnection->getLocalPort())->toBeInt()
        ->and(json_encode($udpConnection))->toBeJson()
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