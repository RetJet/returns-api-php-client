<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RetJetApi\Returns\Exception\AccessDeniedException;
use RetJetApi\Returns\Exception\ApiException;
use RetJetApi\Returns\Exception\AuthenticationException;
use RetJetApi\Returns\Exception\ConfigurationException;
use RetJetApi\Returns\Exception\NotFoundException;
use RetJetApi\Returns\Exception\Problem;
use RetJetApi\Returns\Exception\RateLimitException;
use RetJetApi\Returns\Exception\RetJetException;
use RetJetApi\Returns\Exception\ServerException;
use RetJetApi\Returns\Exception\TransportException;
use RetJetApi\Returns\Exception\ValidationException;
use RetJetApi\Returns\Tests\Support\MockClientException;
use Throwable;

#[CoversClass(ApiException::class)]
#[CoversClass(ValidationException::class)]
#[CoversClass(RateLimitException::class)]
#[CoversClass(TransportException::class)]
final class ExceptionHierarchyTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function everyException(): iterable
    {
        $problem = new Problem(null, 'An error occurred', 500, 'Boom');

        yield 'api' => [new ApiException(400, $problem)];
        yield 'authentication' => [new AuthenticationException(401, $problem)];
        yield 'access denied' => [new AccessDeniedException(403, $problem)];
        yield 'not found' => [new NotFoundException(404, $problem)];
        yield 'validation' => [new ValidationException(422, $problem)];
        yield 'rate limit' => [new RateLimitException(429, $problem)];
        yield 'server' => [new ServerException(500, $problem)];
        yield 'transport' => [new TransportException('offline')];
        yield 'configuration' => [ConfigurationException::missingApiKey()];
    }

    /**
     * One catch block has to cover the whole SDK, so every exception must carry the marker.
     */
    #[DataProvider('everyException')]
    public function testEveryExceptionImplementsTheMarkerInterface(Throwable $exception): void
    {
        self::assertInstanceOf(RetJetException::class, $exception);
    }

    public function testApiExceptionExposesStatusAndProblem(): void
    {
        $problem = new Problem('about:blank', 'An error occurred', 404, 'Not Found', '/v1/x');
        $exception = new ApiException(404, $problem);

        self::assertSame(404, $exception->status());
        self::assertSame(404, $exception->getCode());
        self::assertSame($problem, $exception->problem());
        self::assertSame('HTTP 404: Not Found', $exception->getMessage());
    }

    public function testTheMessageFallsBackWhenTheBodyCarriedNoProblem(): void
    {
        self::assertSame('HTTP 502: RetJet API request failed', (new ApiException(502, new Problem()))->getMessage());
        self::assertSame('HTTP 502: An error occurred', (new ApiException(502, new Problem(null, 'An error occurred')))->getMessage());
    }

    public function testValidationExceptionGroupsAndLooksUpViolations(): void
    {
        $exception = new ValidationException(422, new Problem(), [
            'email' => ['This value is not a valid email address.', 'This value is already used.'],
            '' => ['The request is invalid.'],
        ]);

        self::assertCount(2, $exception->violations());
        self::assertSame(
            ['This value is not a valid email address.', 'This value is already used.'],
            $exception->violationsFor('email'),
        );
        self::assertSame(['The request is invalid.'], $exception->violationsFor(''));
        self::assertSame([], $exception->violationsFor('unknown'), 'Unknown paths yield no messages.');
    }

    public function testRateLimitExceptionDefaultsToAnUnknownDelay(): void
    {
        self::assertNull((new RateLimitException(429, new Problem()))->retryAfter());
        self::assertSame(30, (new RateLimitException(429, new Problem(), 30))->retryAfter());
    }

    public function testTransportExceptionKeepsThePsr18Failure(): void
    {
        $cause = new MockClientException('Connection refused');
        $exception = new TransportException('wrapped', $cause);

        self::assertSame($cause, $exception->getPrevious());
    }
}
