<?php

use Workerman\Connection\ConnectionInterface;
use Workerman\Protocols\Text;

test(Text::class, function () {
    /** @var ConnectionInterface $connection */
    $connection = Mockery::mock(ConnectionInterface::class);

    //::input
    //input too long
    testWithConnectionClose(function ($connection) {
        $connection->maxPackageSize = 5;
        expect(Text::input('abcdefgh', $connection))
            ->toBe(0);
    });
    //input without "\n"
    expect(Text::input('jhdxr', $connection))
        ->toBe(0)
        //input with "\n"
        ->and(Text::input("jhdxr\n", $connection))
        ->toBe(6)
        //::encode
        ->and(Text::encode('jhdxr'))
        ->toBe("jhdxr\n")
        //::decode
        ->and(Text::decode("jhdxr\n"))
        ->toBe('jhdxr');
});