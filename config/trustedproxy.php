<?php

declare(strict_types=1);

/*
 * The file name is imposed by the framework: Illuminate\Http\Middleware\TrustProxies falls back to
 * `config('trustedproxy.proxies')` when no proxy is set through `trustProxies(at: ...)`.
 * That fallback is read while handling a request, so it survives `config:cache` — unlike an `env()` call
 * in bootstrap/app.php, which runs before the environment and the configuration are even loaded.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | The addresses whose X-Forwarded-* headers are believed, as a comma
    | separated list of IPs or CIDR ranges (or `*` to trust whoever connects).
    |
    | Getting this wrong is silent in both directions: too narrow and every
    | generated URL downgrades to http://, too wide and any client can spoof its
    | own address.
    |
    | The default covers the private ranges which is usually a good safe default.
    |
    */

    'proxies' => (string) env('TRUSTED_PROXIES') ?: '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
];
