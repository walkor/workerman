<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

// uses(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

use Workerman\Connection\TcpConnection;

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function testWithConnectionClose(Closure $closure, ?string $dataContains = null, $connectionClass = TcpConnection::class): void
{
    $tcpConnection = Mockery::spy($connectionClass);
    $closure($tcpConnection);
    if ($dataContains) {
        $tcpConnection->shouldHaveReceived('close', function ($actual) use ($dataContains) {
            return str_contains($actual, $dataContains);
        });
    } else {
        $tcpConnection->shouldHaveReceived('close');
    }
}

function getNonFrameOutput(string $output): string
{
    $end = "Start success.\n";
    $pos = strpos($output, $end);
    if ($pos !== false) {
        return substr($output, $pos + strlen($end));
    }
    return $output;
}
