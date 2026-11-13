<?php

// Run with: php tests/HttpRequestMultipartTest.php
require dirname(__DIR__) . '/Autoloader.php';

use Workerman\Protocols\Http\Request;

function createMultipartRequest($contentType, $boundary)
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
        . 'Content-Length: ' . strlen($body) . "\r\n"
        . "\r\n"
        . $body);
}

$validCases = [
    ['multipart/form-data; boundary=ABC123', 'ABC123'],
    ['multipart/form-data; boundary=ABC123; charset=UTF-8', 'ABC123'],
    ['multipart/form-data; boundary=ABC123;charset=UTF-8', 'ABC123'],
    ['multipart/form-data; boundary=ABC123, charset=UTF-8', 'ABC123'],
    ['multipart/form-data; boundary=ABC123 ; charset=UTF-8', 'ABC123'],
    ['multipart/form-data; charset=UTF-8; boundary=ABC123', 'ABC123'],
    ['multipart/form-data; boundary="ABC123"', 'ABC123'],
    ['multipart/form-data; boundary="ABC123"; charset=UTF-8', 'ABC123'],
    ['multipart/form-data; boundary="AB C;1,2:3"; charset=UTF-8', 'AB C;1,2:3'],
    ['multipart/form-data; Boundary=ABC123', 'ABC123'],
    ['multipart/form-data; boundary=----WebKitFormBoundary7MA4YWxkTrZu0gW', '----WebKitFormBoundary7MA4YWxkTrZu0gW'],
];

$malformedContentTypes = [
    'multipart/form-data; boundary=',
    'multipart/form-data; boundary=""',
    'multipart/form-data; boundary="ABC123',
];

$failures = 0;
$checks = 0;
foreach ($validCases as $case) {
    list($contentType, $boundary) = $case;
    $request = createMultipartRequest($contentType, $boundary);
    $file = $request->file('logFile');
    ++$checks;
    if ($request->post() !== ['message' => 'hello']
        || !is_array($file)
        || $file['name'] !== 'test.log'
        || $file['type'] !== 'application/octet-stream'
        || $file['size'] !== 8
        || $file['error'] !== UPLOAD_ERR_OK
        || file_get_contents($file['tmp_name']) !== 'test log'
    ) {
        ++$failures;
        fwrite(STDERR, "Unexpected multipart data for content type '$contentType'\n");
    }
}
foreach ($malformedContentTypes as $contentType) {
    $request = createMultipartRequest($contentType, 'ABC123');
    ++$checks;
    if ($request->file() !== []) {
        ++$failures;
        fwrite(STDERR, "Unexpected files for malformed content type '$contentType'\n");
    }
}
echo "$checks checks, $failures failures\n";
exit($failures ? 1 : 0);
