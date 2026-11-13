<?php

use Workerman\Protocols\Http\Request;

it('parses identical bodies independently of previously cached content types', function (string $firstType, string $secondType, bool $jsonBody) {
    // Unique bodies keep each dataset independent of the process-local cache.
    $id = $firstType . ':' . $secondType . ':' . (int)$jsonBody;
    $body = $jsonBody ? json_encode(['limit' => 20, 'case' => $id]) : http_build_query(['limit' => 20, 'case' => $id]);
    parse_str($body, $formData);
    $jsonData = (array)json_decode($body, true);

    for ($repeat = 0; $repeat < 2; ++$repeat) {
        foreach ([$firstType, $secondType] as $contentType) {
            $headers = $contentType === '' ? '' : "Content-Type: $contentType\r\n";
            $request = new Request("POST / HTTP/1.1\r\nHost: localhost\r\n" . $headers
                . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body);

            expect($request->post())->toBe(str_contains($contentType, 'json') ? $jsonData : $formData);
        }
    }
})->with([
    'form then JSON' => ['application/x-www-form-urlencoded', 'application/json', true],
    'JSON then form' => ['application/json', 'application/x-www-form-urlencoded', true],
    'missing type then JSON' => ['', 'application/json', true],
    'JSON then missing type' => ['application/json', '', true],
    'form body then JSON' => ['application/x-www-form-urlencoded', 'application/json', false],
    'invalid JSON then form' => ['application/json', 'application/x-www-form-urlencoded', false],
    'vendor JSON' => ['application/x-www-form-urlencoded', 'application/problem+json', true],
    'JSON with charset' => ['application/x-www-form-urlencoded', 'application/json; charset=utf-8', true],
]);
