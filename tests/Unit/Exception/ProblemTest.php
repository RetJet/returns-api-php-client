<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RetJetApi\Returns\Exception\Problem;

#[CoversClass(Problem::class)]
final class ProblemTest extends TestCase
{
    public function testItReadsTheFiveRfc7807Members(): void
    {
        $problem = Problem::fromArray([
            'type' => 'https://tools.ietf.org/html/rfc2616#section-10',
            'title' => 'An error occurred',
            'status' => 404,
            'detail' => 'Not Found',
            'instance' => '/v1/rma-requests/9999',
        ]);

        self::assertSame('https://tools.ietf.org/html/rfc2616#section-10', $problem->type());
        self::assertSame('An error occurred', $problem->title());
        self::assertSame(404, $problem->status());
        self::assertSame('Not Found', $problem->detail());
        self::assertSame('/v1/rma-requests/9999', $problem->instance());
    }

    /**
     * The dev environment appends a stack trace to every error response. It must never
     * reach the SDK surface, not even through toArray().
     */
    public function testItIgnoresTheDevOnlyTraceMember(): void
    {
        $problem = Problem::fromArray([
            'title' => 'An error occurred',
            'detail' => 'Unable to exchange token',
            'status' => 500,
            'trace' => [
                ['file' => '/app/src/Kernel.php', 'line' => 42, 'function' => 'handle'],
                ['file' => '/app/vendor/autoload.php', 'line' => 7],
            ],
            'class' => 'App\\Exception\\TokenException',
        ]);

        self::assertSame('Unable to exchange token', $problem->detail());
        self::assertSame(
            ['type', 'title', 'status', 'detail', 'instance'],
            array_keys($problem->toArray()),
        );
        self::assertArrayNotHasKey('trace', $problem->toArray());
    }

    public function testMissingMembersBecomeNull(): void
    {
        $problem = Problem::fromArray([]);

        self::assertNull($problem->type());
        self::assertNull($problem->title());
        self::assertNull($problem->status());
        self::assertNull($problem->detail());
        self::assertNull($problem->instance());
        self::assertNull($problem->summary());
    }

    public function testMembersOfTheWrongTypeAreDiscarded(): void
    {
        $problem = Problem::fromArray([
            'title' => ['not', 'a', 'string'],
            'status' => 'nonsense',
            'detail' => 12,
        ], 400);

        self::assertNull($problem->title());
        self::assertNull($problem->detail());
        self::assertSame(400, $problem->status(), 'Falls back to the HTTP status.');
    }

    public function testANumericStringStatusIsAccepted(): void
    {
        self::assertSame(422, Problem::fromArray(['status' => '422'])->status());
    }

    public function testTheFallbackStatusIsOnlyUsedWhenTheBodyHasNone(): void
    {
        self::assertSame(500, Problem::fromArray(['status' => 500], 503)->status());
        self::assertSame(503, Problem::fromArray([], 503)->status());
    }

    public function testSummaryPrefersDetailOverTitle(): void
    {
        self::assertSame('Details', Problem::fromArray(['title' => 'Title', 'detail' => 'Details'])->summary());
        self::assertSame('Title', Problem::fromArray(['title' => 'Title'])->summary());
    }
}
