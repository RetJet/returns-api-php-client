<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Exception;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RetJetApi\Returns\Http\Redact;
use RuntimeException;
use Throwable;

/**
 * The request never produced an HTTP response: DNS failure, connection refused, TLS error,
 * timeout. Wraps the underlying PSR-18 ClientExceptionInterface, which stays available
 * through getPrevious().
 */
final class TransportException extends RuntimeException implements RetJetException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The URI is redacted before it reaches the message: a base URI may carry credentials
     * (`https://user:pass@host`), and this message is logged, reported and printed in stack
     * traces. Masking headers elsewhere is worthless if the same secret rides in here.
     */
    public static function fromClientException(ClientExceptionInterface $exception, RequestInterface $request): self
    {
        return new self(
            Redact::credentials(sprintf(
                'Request %s %s failed at the transport layer: %s',
                $request->getMethod(),
                (string) $request->getUri(),
                $exception->getMessage(),
            )),
            $exception,
        );
    }
}
