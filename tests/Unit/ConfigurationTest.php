<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RetJetApi\Returns\Configuration;
use RetJetApi\Returns\Exception\ConfigurationException;
use RetJetApi\Returns\Exception\RetJetException;

#[CoversClass(Configuration::class)]
#[CoversClass(ConfigurationException::class)]
final class ConfigurationTest extends TestCase
{
    public function testTheApiKeyIsTheOnlyRequiredInput(): void
    {
        $configuration = new Configuration('secret-key');

        self::assertSame('secret-key', $configuration->apiKey);
        self::assertSame('https://api.retjet.com', $configuration->baseUri);
        self::assertSame(3, $configuration->maxRetries);
        self::assertSame(10, $configuration->timeout);
        self::assertSame(Configuration::defaultUserAgent(), $configuration->userAgent);
    }

    public function testTheDefaultUserAgentNamesTheInstalledPackageVersion(): void
    {
        self::assertMatchesRegularExpression(
            '#^retjet-returns-api-php-client/\S+ \(\+https://github\.com/RetJet/returns-api-php-client\)$#',
            Configuration::defaultUserAgent(),
        );
    }

    public function testItStripsTheTrailingSlashFromTheBaseUri(): void
    {
        $configuration = new Configuration('secret-key', 'https://api.example.com/');

        self::assertSame('https://api.example.com', $configuration->baseUri);
    }

    public function testOverridesAreKept(): void
    {
        $configuration = new Configuration('k', 'http://localhost:8000', 30, 0, 'custom/1.0');

        self::assertSame('http://localhost:8000', $configuration->baseUri);
        self::assertSame(30, $configuration->timeout);
        self::assertSame(0, $configuration->maxRetries);
        self::assertSame('custom/1.0', $configuration->userAgent);
    }

    public function testAnEmptyApiKeyIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        new Configuration('   ');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBaseUris(): iterable
    {
        yield 'empty' => [''];
        yield 'no scheme' => ['api.retjet.com'];
        yield 'relative path' => ['/v1'];
        yield 'unsupported scheme' => ['ftp://api.retjet.com'];
    }

    #[DataProvider('invalidBaseUris')]
    public function testAnInvalidBaseUriIsRejected(string $baseUri): void
    {
        $this->expectException(ConfigurationException::class);

        new Configuration('k', $baseUri);
    }

    /**
     * Zero is rejected alongside the negatives: Guzzle's `timeout` and CURLOPT_TIMEOUT both
     * read 0 as "wait forever", so accepting it would turn withTimeout(0) into the opposite
     * of a timeout instead of failing fast.
     *
     * @return iterable<string, array{int}>
     */
    public static function unusableTimeouts(): iterable
    {
        yield 'negative' => [-1];
        yield 'zero means no timeout downstream' => [0];
    }

    #[DataProvider('unusableTimeouts')]
    public function testAnUnusableTimeoutIsRejected(int $timeout): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('at least 1 second');

        new Configuration('k', Configuration::DEFAULT_BASE_URI, $timeout);
    }

    public function testTheSmallestUsableTimeoutIsAccepted(): void
    {
        self::assertSame(1, (new Configuration('k', Configuration::DEFAULT_BASE_URI, 1))->timeout);
    }

    public function testNegativeRetriesAreRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        new Configuration('k', Configuration::DEFAULT_BASE_URI, 10, -1);
    }

    public function testConfigurationExceptionIsPartOfTheSdkHierarchy(): void
    {
        $this->expectException(RetJetException::class);

        new Configuration('');
    }
}
