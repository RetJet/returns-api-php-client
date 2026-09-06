<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * A PSR-18 client failure, used to drive the transport-error path in tests.
 */
final class MockClientException extends RuntimeException implements ClientExceptionInterface
{
}
