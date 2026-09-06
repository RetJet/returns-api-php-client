<?php

declare(strict_types=1);

namespace RetJetApi\Returns;

use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use RetJetApi\Returns\Http\Transport;
use RetJetApi\Returns\Resource\ItemConditions;
use RetJetApi\Returns\Resource\ItemReasons;
use RetJetApi\Returns\Resource\ItemResolutions;
use RetJetApi\Returns\Resource\OrderedProducts;
use RetJetApi\Returns\Resource\ReturnPoints;
use RetJetApi\Returns\Resource\RmaRequests;
use RetJetApi\Returns\Resource\SaleChannels;

/**
 * Entry point of the SDK: one `use` gets you everything.
 *
 *     $client = Client::create('YOUR_API_KEY');
 *     $page   = $client->saleChannels()->list();
 *
 * There is no separate facade class - the static factories live here, so the whole library
 * starts from `use RetJetApi\Returns\Client;`. Reach for builder() only when a default needs
 * overriding.
 */
final readonly class Client
{
    public function __construct(
        private Transport $transport,
        private Configuration $configuration,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The whole configuration most callers need: an API key, and nothing else.
     *
     * The optional PSR-18 client is there for containers that already own one (and for
     * tests); leave it out and the SDK builds a client honouring Configuration::$timeout.
     */
    public static function create(string $apiKey, ?ClientInterface $httpClient = null): self
    {
        $builder = self::builder()->withApiKey($apiKey);

        if ($httpClient !== null) {
            $builder->withHttpClient($httpClient);
        }

        return $builder->build();
    }

    /**
     * Fluent configuration, for everything create() does not cover.
     */
    public static function builder(): ClientBuilder
    {
        return new ClientBuilder();
    }

    public function configuration(): Configuration
    {
        return $this->configuration;
    }

    /**
     * The transport every resource goes through. Useful for calling an endpoint the SDK does
     * not wrap yet.
     */
    public function transport(): Transport
    {
        return $this->transport;
    }

    /**
     * The logger the client was configured with, if any. It is already wired into the
     * transport stack as a LoggingMiddleware; this accessor is for code that wants to log
     * alongside the SDK using the same logger.
     */
    public function logger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Return requests: the collection, one request, and every action on one.
     */
    public function rmaRequests(): RmaRequests
    {
        return new RmaRequests($this->transport);
    }

    public function saleChannels(): SaleChannels
    {
        return new SaleChannels($this->transport);
    }

    public function returnPoints(): ReturnPoints
    {
        return new ReturnPoints($this->transport);
    }

    public function orderedProducts(): OrderedProducts
    {
        return new OrderedProducts($this->transport);
    }

    public function itemConditions(): ItemConditions
    {
        return new ItemConditions($this->transport);
    }

    public function itemReasons(): ItemReasons
    {
        return new ItemReasons($this->transport);
    }

    public function itemResolutions(): ItemResolutions
    {
        return new ItemResolutions($this->transport);
    }
}
