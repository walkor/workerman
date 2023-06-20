<?php

use Workerman\Protocols\Http\Response;

it('test some simple case', function () {
    $response = new Response(201, ['X-foo' => 'bar'], 'hello, xiami');

    expect($response->getStatusCode())->toBe(201)
        ->and($response->getHeaders())->toBe(['X-foo' => 'bar'])
        ->and($response->rawBody())->toBe('hello, xiami');

    //headers
    $response->header('abc', '123');
    $response->withHeader('X-foo', 'baz');
    $response->withHeaders(['def' => '456']);
    expect((string)$response)
        ->toContain('X-foo: baz')
        ->toContain('abc: 123')
        ->toContain('def: 456');
    $response->withoutHeader('def');
    expect((string)$response)->not->toContain('def: 456')
        ->and($response->getHeader('abc'))
        ->toBe('123');

    $response->withStatus(202, 'some reason');
    expect($response->getReasonPhrase())->toBe('some reason');

    $response->withProtocolVersion('1.0');
    $response->withBody('hello, world');
    expect((string)$response)
        ->toContain('HTTP/1.0')
        ->toContain('hello, world')
        ->toContain('Content-Type: ')
        ->toContain('Content-Length: 12')
        ->not()->toContain('Transfer-Encoding: ');


    //cookie
    $response->cookie('foo', 'bar', domain: 'xia.moe', httpOnly: true);
    expect((string)$response)
        ->toContain('Set-Cookie: foo=bar; Domain=xia.moe; HttpOnly');
});

it('tests file', function (){
    //todo may have to redo the simple test,
    // as the implementation of headers is a different function for files.
    // or actually maybe the Response is the one should be rewritten to reuse?
    $response = new Response();
    $tmpFile = tempnam(sys_get_temp_dir(), 'test');
    rename($tmpFile, $tmpFile .'.jpg');
    $tmpFile .= '.jpg';
    file_put_contents($tmpFile, 'hello, xiami');
    $response->withFile($tmpFile, 0, 12);
    expect((string)$response)
        ->toContain('Content-Type: image/jpeg')
        ->toContain('Last-Modified: ');
});