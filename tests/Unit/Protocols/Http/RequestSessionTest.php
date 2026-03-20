<?php

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Session;
use Workerman\Protocols\Http\Session\FileSessionHandler;

/**
 * @param Closure(Request): void $callback
 */
function withSessionRequest(Closure $callback, bool $customCookieParams = false): void
{
    $origLifetime = Session::$cookieLifetime;
    $origPath = Session::$cookiePath;
    $origDomain = Session::$domain;
    $origSecure = Session::$secure;
    $origHttpOnly = Session::$httpOnly;
    $origSameSite = Session::$sameSite;

    if ($customCookieParams) {
        Session::$cookieLifetime = 7200;
        Session::$cookiePath = '/app';
        Session::$domain = 'example.com';
        Session::$secure = true;
        Session::$httpOnly = true;
        Session::$sameSite = 'Lax';
    }

    $sessionSavePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'workerman_test_' . uniqid() . DIRECTORY_SEPARATOR;
    FileSessionHandler::sessionSavePath($sessionSavePath);

    $buffer = "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
    $request = new Request($buffer);
    /** @var TcpConnection $connection */
    $connection = Mockery::mock(TcpConnection::class);
    $connection->headers = [];
    $request->connection = $connection;

    try {
        $callback($request);
    } finally {
        $request->destroy();

        Session::$cookieLifetime = $origLifetime;
        Session::$cookiePath = $origPath;
        Session::$domain = $origDomain;
        Session::$secure = $origSecure;
        Session::$httpOnly = $origHttpOnly;
        Session::$sameSite = $origSameSite;

        $files = glob($sessionSavePath . '*');
        if ($files) {
            array_map('unlink', array_filter($files, 'is_file'));
        }
        if (is_dir($sessionSavePath)) {
            @rmdir($sessionSavePath);
        }
    }
}

// ─── sessionRegenerateId ────────────────────────────────────────────────────

describe('sessionRegenerateId', function () {

    it('returns a new session id different from the original', function () {
        withSessionRequest(function (Request $request) {
            $oldSid = $request->sessionId();
            $newSid = $request->sessionRegenerateId();

            expect($newSid)->not->toBe($oldSid)
                ->and($newSid)->toMatch('/^[a-zA-Z0-9,-]{16,256}$/');
        });
    });

    it('updates sessionId() to return the regenerated id', function () {
        withSessionRequest(function (Request $request) {
            $request->sessionId();
            $newSid = $request->sessionRegenerateId();

            expect($request->sessionId())->toBe($newSid);
        });
    });

    it('updates session() to return a new Session instance with the regenerated id', function () {
        withSessionRequest(function (Request $request) {
            $oldSession = $request->session();
            $newSid = $request->sessionRegenerateId();
            $newSession = $request->session();

            expect($newSession)->not->toBe($oldSession)
                ->and($newSession->getId())->toBe($newSid);
        });
    });

    it('migrates existing session data to the new session', function () {
        withSessionRequest(function (Request $request) {
            $request->session()->set('user_id', 42);
            $request->session()->set('role', 'admin');

            $request->sessionRegenerateId();

            expect($request->session()->get('user_id'))->toBe(42)
                ->and($request->session()->get('role'))->toBe('admin');
        });
    });

    it('preserves old session in storage when deleteOldSession is false', function () {
        withSessionRequest(function (Request $request) {
            $oldSid = $request->sessionId();
            $request->session()->set('key', 'value');
            $request->session()->save();

            $request->sessionRegenerateId(false);

            $reloaded = new Session($oldSid);
            expect($reloaded->get('key'))->toBe('value');
        });
    });

    it('destroys old session in storage when deleteOldSession is true', function () {
        withSessionRequest(function (Request $request) {
            $oldSid = $request->sessionId();
            $request->session()->set('key', 'value');
            $request->session()->save();

            $request->sessionRegenerateId(true);

            $reloaded = new Session($oldSid);
            expect($reloaded->all())->toBe([]);
        });
    });

    it('writes Set-Cookie header with correct cookie attributes', function () {
        withSessionRequest(function (Request $request) {
            $newSid = $request->sessionRegenerateId();

            $connection = $request->connection;
            assert($connection !== null);
            $cookies = $connection->headers['Set-Cookie'] ?? [];
            expect($cookies)->toBeArray()->toHaveCount(1);

            $cookie = $cookies[0];
            expect($cookie)
                ->toStartWith(Session::$name . '=' . $newSid)
                ->toContain('Domain=example.com')
                ->toContain('Max-Age=7200')
                ->toContain('Path=/app')
                ->toContain('SameSite=Lax')
                ->toContain('Secure')
                ->toContain('HttpOnly');
        }, customCookieParams: true);
    });

    it('stays consistent after multiple regenerations', function () {
        withSessionRequest(function (Request $request) {
            $request->session()->set('counter', 1);

            $sid1 = $request->sessionRegenerateId();
            expect($request->sessionId())->toBe($sid1)
                ->and($request->session()->getId())->toBe($sid1)
                ->and($request->session()->get('counter'))->toBe(1);

            $request->session()->set('counter', 2);
            $sid2 = $request->sessionRegenerateId();
            expect($request->sessionId())->toBe($sid2)
                ->and($request->session()->getId())->toBe($sid2)
                ->and($request->session()->get('counter'))->toBe(2)
                ->and($sid2)->not->toBe($sid1);
        });
    });
});

// ─── sessionId setter ───────────────────────────────────────────────────────

describe('sessionId setter', function () {

    it('switches sessionId() and session() to the new id', function () {
        withSessionRequest(function (Request $request) {
            $oldSession = $request->session();

            $newSid = str_repeat('A', 32);
            $request->sessionId($newSid);

            expect($request->sessionId())->toBe($newSid)
                ->and($request->session()->getId())->toBe($newSid)
                ->and($request->session())->not->toBe($oldSession);
        });
    });

    it('does not migrate old session data to the new session', function () {
        withSessionRequest(function (Request $request) {
            $request->session()->set('user_id', 42);
            $request->session()->set('role', 'admin');

            $newSid = str_repeat('B', 32);
            $request->sessionId($newSid);

            expect($request->session()->get('user_id'))->toBeNull()
                ->and($request->session()->get('role'))->toBeNull()
                ->and($request->session()->all())->toBe([]);
        });
    });

    it('persists old session data to storage when switching', function () {
        withSessionRequest(function (Request $request) {
            $oldSid = $request->sessionId();
            $request->session()->set('key', 'value');
            $request->session()->save();

            $newSid = str_repeat('C', 32);
            $request->sessionId($newSid);

            $reloaded = new Session($oldSid);
            expect($reloaded->get('key'))->toBe('value');
        });
    });

    it('saves unsaved in-memory modifications of old session before switching', function () {
        withSessionRequest(function (Request $request) {
            $oldSid = $request->sessionId();
            $request->session()->set('saved_key', 'saved_val');
            $request->session()->save();
            $request->session()->set('unsaved_key', 'unsaved_val');

            $newSid = str_repeat('D', 32);
            $request->sessionId($newSid);

            $reloaded = new Session($oldSid);
            expect($reloaded->get('saved_key'))->toBe('saved_val')
                ->and($reloaded->get('unsaved_key'))->toBe('unsaved_val');
        });
    });

    it('writes Set-Cookie header with correct cookie attributes', function () {
        withSessionRequest(function (Request $request) {
            $newSid = str_repeat('E', 32);
            $request->sessionId($newSid);

            $connection = $request->connection;
            assert($connection !== null);
            $cookies = $connection->headers['Set-Cookie'] ?? [];
            expect($cookies)->toBeArray()->toHaveCount(1);

            $cookie = $cookies[0];
            expect($cookie)
                ->toStartWith(Session::$name . '=' . $newSid)
                ->toContain('Domain=example.com')
                ->toContain('Max-Age=7200')
                ->toContain('Path=/app')
                ->toContain('SameSite=Lax')
                ->toContain('Secure')
                ->toContain('HttpOnly');
        }, customCookieParams: true);
    });

    it('loads pre-existing data when switching to an existing session id', function () {
        withSessionRequest(function (Request $request) {
            $existingSid = str_repeat('F', 32);
            $pre = new Session($existingSid);
            $pre->set('pre_key', 'pre_val');
            $pre->save();

            $request->session()->set('current', 'data');
            $request->sessionId($existingSid);

            expect($request->session()->get('pre_key'))->toBe('pre_val')
                ->and($request->session()->get('current'))->toBeNull();
        });
    });
});
