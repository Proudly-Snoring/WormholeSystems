<?php

declare(strict_types=1);

/*
 * deploy/.env.example ships the optional keys bare (`REDIS_PORT=`) and the deployment loads its env
 * file verbatim, so the application reads an empty string rather than a missing variable — the
 * second argument of env() would never fire. An empty port used to reach Redis::connect(), which
 * takes an int, and killed every container of the stack on boot.
 *
 * Reading the config file directly is the only way to see this: it is evaluated at boot, before a
 * test runs.
 */

/**
 * Point every source `env()` reads at the given value, or remove the variable from all of them when
 * null, and return the closure restoring the previous state.
 *
 * `putenv()` alone is not enough: the dotenv repository reads `$_SERVER` and `$_ENV` before the
 * putenv adapter, and loading a .env file that carries the key (which CI does, cf. .env.example)
 * fills all three — so the value from the file would shadow anything this test sets.
 */
function useRedisVariable(string $key, ?string $value): Closure
{
    $previous_server = $_SERVER[$key] ?? null;
    $previous_env = $_ENV[$key] ?? null;
    $previous_putenv = getenv($key);

    $apply = function (?string $value) use ($key): void {
        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);

            return;
        }

        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    };

    $apply($value);

    return function () use ($apply, $key, $previous_server, $previous_env, $previous_putenv): void {
        $apply(null);

        if ($previous_server !== null) {
            $_SERVER[$key] = $previous_server;
        }

        if ($previous_env !== null) {
            $_ENV[$key] = $previous_env;
        }

        if ($previous_putenv !== false) {
            putenv("{$key}={$previous_putenv}");
        }
    };
}

/**
 * The redis section of the freshly evaluated config file, with the given variable in place.
 *
 * @return array{default: array<string, mixed>, cache: array<string, mixed>}
 */
function redisConfigWith(string $key, ?string $value): array
{
    $restore = useRedisVariable($key, $value);

    try {
        return (require config_path('database.php'))['redis'];
    } finally {
        $restore();
    }
}

/**
 * The redis server Reverb scales through, from the freshly evaluated config file. It reads the very
 * same REDIS_* variables, so it has to resolve blank values the same way.
 *
 * @return array<string, mixed>
 */
function reverbScalingServerWith(string $key, ?string $value): array
{
    $restore = useRedisVariable($key, $value);

    try {
        return (require config_path('reverb.php'))['servers']['reverb']['scaling']['server'];
    } finally {
        $restore();
    }
}

it('reads the redis port from the environment as an int', function (?string $environment, int $expected): void {
    $redis = redisConfigWith('REDIS_PORT', $environment);

    expect($redis['default']['port'])->toBe($expected)
        ->and($redis['cache']['port'])->toBe($expected)
        ->and(reverbScalingServerWith('REDIS_PORT', $environment)['port'])->toBe($expected);
})->with([
    'unset' => [null, 6379],
    'empty' => ['', 6379],
    'a custom port' => ['6380', 6380],
]);

it('reads the redis host from the environment', function (?string $environment, string $expected): void {
    $redis = redisConfigWith('REDIS_HOST', $environment);

    expect($redis['default']['host'])->toBe($expected)
        ->and($redis['cache']['host'])->toBe($expected);
})->with([
    'unset' => [null, '127.0.0.1'],
    'empty' => ['', '127.0.0.1'],
    'a service name' => ['redis', 'redis'],
    'a TLS prefixed host' => ['tls://example.com', 'tls://example.com'],
]);

/*
 * A blank credential must be absent rather than empty: the internal redis of the deploy stack runs
 * without a password, and an empty string would otherwise be offered to AUTH.
 */
it('treats a blank redis credential as absent', function (string $key, string $setting): void {
    expect(redisConfigWith($key, '')['default'][$setting])->toBeNull()
        ->and(redisConfigWith($key, null)['default'][$setting])->toBeNull()
        ->and(redisConfigWith($key, 'secret')['default'][$setting])->toBe('secret');
})->with([
    'username' => ['REDIS_USERNAME', 'username'],
    'password' => ['REDIS_PASSWORD', 'password'],
]);
