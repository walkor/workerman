<?php

// Run with: php tests/HttpRequestPostCacheTest.php
require dirname(__DIR__) . '/Autoloader.php';

use Workerman\Protocols\Http\Request;

$cases = [
    ['application/x-www-form-urlencoded', 'application/json', true],
    ['application/json', 'application/x-www-form-urlencoded', true],
    ['', 'application/json', true],
    ['application/json', '', true],
    ['application/x-www-form-urlencoded', 'application/json', false],
    ['application/json', 'application/x-www-form-urlencoded', false],
    ['application/x-www-form-urlencoded', 'application/problem+json', true],
    ['application/x-www-form-urlencoded', 'application/json; charset=utf-8', true],
    ['application/x-www-form-urlencoded', 'APPLICATION/JSON', true],
];

$failures = 0;
$checks = 0;
foreach ($cases as $case) {
    list($firstType, $secondType, $jsonBody) = $case;
    $id = $firstType . ':' . $secondType . ':' . (int)$jsonBody;
    $body = $jsonBody ? json_encode(['limit' => 20, 'case' => $id]) : http_build_query(['limit' => 20, 'case' => $id]);
    parse_str($body, $formData);
    $jsonData = (array)json_decode($body, true);
    for ($repeat = 0; $repeat < 2; ++$repeat) {
        foreach ([$firstType, $secondType] as $contentType) {
            $headers = $contentType === '' ? '' : "Content-Type: $contentType\r\n";
            $request = new Request("POST / HTTP/1.1\r\nHost: localhost\r\n" . $headers
                . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body);
            $expected = preg_match('/\\bjson\\b/i', $contentType) ? $jsonData : $formData;
            ++$checks;
            if ($request->post() !== $expected) {
                ++$failures;
                fwrite(STDERR, "Unexpected POST data for $id, content type '$contentType'\n");
            }
        }
    }
}
echo "$checks checks, $failures failures\n";
exit($failures ? 1 : 0);
