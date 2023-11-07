<?php

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\Utils;
use Symfony\Component\Process\PhpProcess;

$process = null;
beforeAll(function () use (&$process) {
    $process = new PhpProcess(file_get_contents(__DIR__ . '/Stub/HttpServer.php'));
    $process->start();
    usleep(250000);
});

afterAll(function () use (&$process) {
    echo $process->getOutput();
    $process->stop();
});

it('tests http connection', function () {
    $client = new Client([
        'base_uri' => 'http://127.0.0.1:8080',
        'cookies' => true,
        'http_errors' => false,
    ]);

    $response = $client->get('/');
    expect($response->getStatusCode())
        ->toBe(200)
        ->and($response->getHeaderLine('Server'))
        ->tobe('workerman')
        ->and($response->getHeaderLine('Content-Length'))
        ->tobe('12')
        ->and($response->getBody()->getContents())
        ->toBe('Hello Chance');

    $data = [
        'foo' => 'bar',
        'key' => ['hello', 'chance']
    ];
    $response = $client->get('/get', [
        'query' => $data
    ]);
    expect($response->getBody()->getContents())
        ->toBeJson()
        ->json()
        ->toBe($data);

    $response = $client->post('/post', [
        'json' => $data
    ]);
    expect($response->getBody()->getContents())
        ->toBeJson()
        ->json()
        ->toBe($data);

    $response = $client->post('/header', [
        'headers' => [
            'foo' => 'bar'
        ]
    ]);
    expect($response->getBody()->getContents())
        ->toBe('bar');

    $cookie = new CookieJar();
    $client->get('/setSession', [
        'cookies' => $cookie
    ]);
    $response = $client->get('/session', [
        'cookies' => $cookie
    ]);
    expect($response->getBody()->getContents())
        ->toBe('bar');
    $response = $client->get('/session', [
        'cookies' => $cookie
    ]);
    expect($response->getBody()->getContents())
        ->toBe('');

    $response = $client->get('/sse', [
        'stream' => true,
    ]);
    $stream = $response->getBody();
    $i = 0;
    while (!$stream->eof()) {
        if ($i >= 5) {
            expect($stream->read(1024))->toBeEmpty();
            continue;
        }
        $i++;
        expect($stream->read(1024))->toBe("data: hello$i\n\n");
    }

    $file = Utils::tryFopen(__DIR__ . '/Stub/HttpServer.php', 'r');
    $response = $client->post('/file', [
        'multipart' => [
            [
                'name' => 'file',
                'contents' => $file
            ]
        ]
    ]);
    expect($response->getBody()->getContents())
        ->toBeJson()
        ->json()
        ->toMatchArray([
            'name' => 'HttpServer.php',
            'error' => 0,
        ]);

    $response = $client->get('/404');
    expect($response->getStatusCode())
        ->toBe(404)
        ->and($response->getBody()->getContents())
        ->toBe('404 not found');
});