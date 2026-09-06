<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Http;

use RetJetApi\Returns\Exception\RetJetException;

/**
 * The single seam between the SDK and the network.
 *
 * One method, deliberately: everything above this interface (resources, the paginator)
 * expresses a call as method + path + query + body + headers, and everything that wraps it
 * (retry, logging, caching) only has to forward one call and can replay it verbatim.
 *
 * `$path` accepts an absolute URL as well as a path, so the paginator can follow a Hydra
 * `view.next` link without reconstructing it. The return value is the decoded response body;
 * error statuses never come back as data, they are thrown.
 */
interface Transport
{
    /**
     * @param string                       $method  HTTP method
     * @param string                       $path    path relative to the base URI, or an absolute URL
     * @param array<string, scalar|null>   $query   query parameters; null drops the parameter
     * @param array<array-key, mixed>|null $body    JSON request body; null sends none
     * @param array<string, string>        $headers per-request header overrides
     *
     * @return array<string, mixed> decoded response body; empty for a no-content response
     *
     * @throws RetJetException
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        array $headers = [],
    ): array;
}
