<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * The probe is requested through its absolute http:// address rather than the '/_proxy-probe'
 * path: a relative URI is resolved against APP_URL, which is https in .env.example — the request
 * would then already be secure before any middleware runs, and every expectation below about an
 * untrusted client would hold for the wrong reason.
 */
const PROXY_PROBE_URL = 'http://localhost/_proxy-probe';

beforeEach(function (): void {
    Route::middleware('web')->get('/_proxy-probe', fn (): array => [
        'secure' => request()->isSecure(),
        'ip' => request()->ip(),
        'url' => url('/somewhere'),
    ]);
});

it('trusts the forwarded headers of a proxy on a private network', function (string $proxy_ip): void {
    $response = $this->withServerVariables(['REMOTE_ADDR' => $proxy_ip])
        ->get(PROXY_PROBE_URL, [
            'X-Forwarded-For' => '203.0.113.7',
            'X-Forwarded-Host' => 'mapper.example',
            'X-Forwarded-Proto' => 'https',
        ]);

    $response->assertSuccessful();

    expect($response->json('secure'))->toBeTrue()
        ->and($response->json('ip'))->toBe('203.0.113.7')
        ->and($response->json('url'))->toBe('https://mapper.example/somewhere');
})->with([
    '10.0.0.0/8' => '10.1.2.3',
    '172.16.0.0/12' => '172.18.0.5',
    '192.168.0.0/16' => '192.168.1.9',
]);

it('ignores the forwarded headers of a client that is not a trusted proxy', function (string $client_ip): void {
    $response = $this->withServerVariables(['REMOTE_ADDR' => $client_ip])
        ->get(PROXY_PROBE_URL, [
            'X-Forwarded-For' => '198.51.100.4',
            'X-Forwarded-Host' => 'evil.example',
            'X-Forwarded-Proto' => 'https',
        ]);

    $response->assertSuccessful();

    expect($response->json('secure'))->toBeFalse()
        ->and($response->json('ip'))->toBe($client_ip)
        ->and($response->json('url'))->not->toContain('evil.example');
})->with([
    'public address' => '203.0.113.10',
    // Loopback is deliberately not trusted either: nothing legitimately proxies over it in this
    // stack, and the container healthcheck that does use it speaks plain HTTP anyway.
    'loopback' => '127.0.0.1',
]);

it('trusts the proxies named by the configuration rather than the private ranges', function (string $configured, string $remote_ip, bool $trusted): void {
    config(['trustedproxy.proxies' => $configured]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => $remote_ip])
        ->get(PROXY_PROBE_URL, [
            'X-Forwarded-For' => '198.51.100.4',
            'X-Forwarded-Proto' => 'https',
        ]);

    expect($response->json('secure'))->toBe($trusted)
        ->and($response->json('ip'))->toBe($trusted ? '198.51.100.4' : $remote_ip);
})->with([
    'configured range' => ['203.0.113.0/24', '203.0.113.5', true],
    'private range, not configured' => ['203.0.113.0/24', '10.1.2.3', false],
    'wildcard' => ['*', '203.0.113.10', true],
]);

/**
 * Point every source `env()` reads at the given value, or remove the variable from all of them
 * when null, and return the closure restoring the previous state.
 *
 * `putenv()` alone is not enough: the dotenv repository reads `$_SERVER` and `$_ENV` before the
 * putenv adapter, and loading a .env file that carries the key (which CI does, cf. .env.example)
 * fills all three — so the value from the file would shadow anything this test sets.
 */
function useTrustedProxies(?string $value): Closure
{
    $previous_server = $_SERVER['TRUSTED_PROXIES'] ?? null;
    $previous_env = $_ENV['TRUSTED_PROXIES'] ?? null;
    $previous_putenv = getenv('TRUSTED_PROXIES');

    $apply = function (?string $value): void {
        if ($value === null) {
            unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
            putenv('TRUSTED_PROXIES');

            return;
        }

        $_SERVER['TRUSTED_PROXIES'] = $value;
        $_ENV['TRUSTED_PROXIES'] = $value;
        putenv("TRUSTED_PROXIES={$value}");
    };

    $apply($value);

    return function () use ($apply, $previous_server, $previous_env, $previous_putenv): void {
        $apply(null);

        if ($previous_server !== null) {
            $_SERVER['TRUSTED_PROXIES'] = $previous_server;
        }

        if ($previous_env !== null) {
            $_ENV['TRUSTED_PROXIES'] = $previous_env;
        }

        if ($previous_putenv !== false) {
            putenv("TRUSTED_PROXIES={$previous_putenv}");
        }
    };
}

/*
 * .env.example ships the key bare (`TRUSTED_PROXIES=`) and the deployment loads its env file
 * verbatim, so the application reads an empty string rather than a missing variable — the second
 * argument of env() would never fire, and an empty value must fall back on its own. Reading the
 * config file directly is the only way to see it: it is evaluated at boot, before a test runs.
 */
it('reads the trusted proxies from the environment', function (?string $environment, string $expected): void {
    $restore = useTrustedProxies($environment);

    try {
        expect(require config_path('trustedproxy.php'))->toBe(['proxies' => $expected]);
    } finally {
        $restore();
    }
})->with([
    'unset' => [null, '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'],
    'empty' => ['', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'],
    'a list of ranges' => ['203.0.113.0/24,198.51.100.7', '203.0.113.0/24,198.51.100.7'],
    'a wildcard' => ['*', '*'],
]);
