<?php

use Workerman\Protocols\Http\ServerSentEvents;

it('tests ' . ServerSentEvents::class, function () {
    $data = [
        'event' => 'ping',
        'data' => 'some thing',
        'id' => 1000,
        'retry' => 5000,
    ];
    $sse = new ServerSentEvents($data);
    $expected = "event: {$data['event']}\ndata: {$data['data']}\n\nid: {$data['id']}\nretry: {$data['retry']}\n";
    expect((string)$sse)->toBe($expected);
});