<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Http\Middleware;

use RetJetApi\Returns\Http\Transport;

/**
 * A Transport that wraps another Transport.
 *
 * Middleware is plain decoration: it takes the transport it delegates to as its first
 * constructor argument, and everything above it - resources, the paginator, the client -
 * cannot tell the difference. That is the whole contract, which is why this interface adds
 * no methods of its own: implementing it is a statement of intent ("I decorate"), the same
 * way RetJetException marks the SDK's exceptions.
 *
 * The alternative - a `process($method, ..., Transport $next)` hook plus a stack that wires
 * everything up - was rejected: it duplicates the six-parameter signature of Transport for no
 * gain, and it stops a middleware from being usable anywhere a Transport is expected.
 *
 * Because the inner transport only exists once ClientBuilder::build() runs, custom middleware
 * is registered as a factory rather than an instance:
 *
 *     Client::builder()
 *         ->withApiKey($key)
 *         ->withMiddleware(fn (Transport $next) => new CacheMiddleware($next, $pool))
 *         ->build();
 */
interface MiddlewareInterface extends Transport
{
}
