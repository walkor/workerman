<?php

use Workerman\Protocols\Http\Request;

function createMultipartRequest(string $contentType, string $boundary): Request
{
    $body = "--$boundary\r\n"
        . "Content-Disposition: form-data; name=\"message\"\r\n"
        . "\r\n"
        . "hello\r\n"
        . "--$boundary\r\n"
        . "Content-Disposition: form-data; name=\"logFile\"; filename=\"test.log\"\r\n"
        . "Content-Type: application/octet-stream\r\n"
        . "\r\n"
        . "test log\r\n"
        . "--$boundary--\r\n";
    return new Request("POST /upload HTTP/1.1\r\n"
        . "Host: localhost\r\n"
        . "Content-Type: $contentType\r\n"
        . "Content-Length: " . strlen($body) . "\r\n"
        . "\r\n"
        . $body);
}

it('extracts multipart boundary from content-type', function (string $contentType, string $boundary) {
    $request = createMultipartRequest($contentType, $boundary);

    expect($request->post())->toBe(['message' => 'hello']);

    $file = $request->file('logFile');
    expect($file)->toMatchArray([
        'name' => 'test.log',
        'type' => 'application/octet-stream',
        'size' => 8,
        'error' => UPLOAD_ERR_OK,
    ])->and(file_get_contents($file['tmp_name']))->toBe('test log');
})->with([
    'plain' => ['multipart/form-data; boundary=ABC123', 'ABC123'],
    'followed by parameter' => ['multipart/form-data; boundary=ABC123; charset=UTF-8', 'ABC123'],
    'followed by parameter without space' => ['multipart/form-data; boundary=ABC123;charset=UTF-8', 'ABC123'],
    'followed by comma' => ['multipart/form-data; boundary=ABC123, charset=UTF-8', 'ABC123'],
    'followed by whitespace' => ['multipart/form-data; boundary=ABC123 ; charset=UTF-8', 'ABC123'],
    'preceded by parameter' => ['multipart/form-data; charset=UTF-8; boundary=ABC123', 'ABC123'],
    'quoted' => ['multipart/form-data; boundary="ABC123"', 'ABC123'],
    'quoted followed by parameter' => ['multipart/form-data; boundary="ABC123"; charset=UTF-8', 'ABC123'],
    'quoted with special chars' => ['multipart/form-data; boundary="AB C;1,2:3"; charset=UTF-8', 'AB C;1,2:3'],
    'case-insensitive parameter name' => ['multipart/form-data; Boundary=ABC123', 'ABC123'],
    'browser style' => [
        'multipart/form-data; boundary=----WebKitFormBoundary7MA4YWxkTrZu0gW',
        '----WebKitFormBoundary7MA4YWxkTrZu0gW',
    ],
]);

it('ignores malformed multipart boundary', function (string $contentType) {
    $request = createMultipartRequest($contentType, 'ABC123');

    expect($request->file())->toBe([]);
})->with([
    'empty' => ['multipart/form-data; boundary='],
    'empty quoted' => ['multipart/form-data; boundary=""'],
    'unterminated quote' => ['multipart/form-data; boundary="ABC123'],
]);
