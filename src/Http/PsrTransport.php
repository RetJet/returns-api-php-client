<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Http;

use Http\Discovery\Exception\NotFoundException as DiscoveryNotFoundException;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use RetJetApi\Returns\Configuration;
use RetJetApi\Returns\Exception\ConfigurationException;
use RetJetApi\Returns\Exception\TransportException;

/**
 * Transport backed by any PSR-18 client.
 *
 * Note on Configuration::$timeout: PSR-18 has no notion of a timeout, so it cannot be
 * enforced here. It is honoured by whoever constructs the HTTP client.
 */
final class PsrTransport implements Transport
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestBuilder $requestBuilder,
        private readonly ResponseParser $responseParser,
    ) {
    }

    /**
     * @throws ConfigurationException when no PSR-18 client or PSR-17 factory is installed
     */
    public static function create(Configuration $configuration, ?ClientInterface $client = null): self
    {
        try {
            $client ??= Psr18ClientDiscovery::find();
        } catch (DiscoveryNotFoundException $exception) {
            throw ConfigurationException::missingDiscovery('PSR-18 HTTP client', $exception);
        }

        return new self($client, RequestBuilder::create($configuration), new ResponseParser());
    }

    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        array $headers = [],
    ): array {
        $request = $this->requestBuilder->build($method, $path, $query, $body, $headers);

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw TransportException::fromClientException($exception, $request);
        }

        return $this->responseParser->parse($response, $request);
    }
}
