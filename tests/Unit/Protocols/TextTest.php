<?php

use Workerman\Connection\ConnectionInterface;
use Workerman\Protocols\Text;

test(Text::class, function () {
    $connection = Mockery::mock(ConnectionInterface::class);

    //::input
    //input without "\n"
    expect(Text::input('jhdxr', $connection))
        ->toBe(0);
    //input with "\n"
    expect(Text::input("jhdxr\n", $connection))
        ->toBe(6);

    //::encode
    expect(Text::encode('jhdxr'))
        ->toBe("jhdxr\n");

    //::decode
    expect(Text::decode("jhdxr\n"))
        ->toBe('jhdxr');
});