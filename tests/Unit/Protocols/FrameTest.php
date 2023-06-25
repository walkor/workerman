<?php

use Workerman\Protocols\Frame;

it('tests ::input', function () {
    expect(Frame::input('foo'))->toBe(0)
        ->and(Frame::input("\0\0\0*foobar"))
        ->toBe(42);
});

it('tests ::decode', function () {
    $buffer = pack('N', 5) . 'jhdxr';
    expect(Frame::decode($buffer))
        ->toBe('jhdxr');
});

it('tests ::encode', function () {
    expect(Frame::encode('jhdxr'))
        ->toBe(pack('N', 9) . 'jhdxr');
});