<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RetJetApi\Returns\Configuration;
use RetJetApi\Returns\Exception\ConfigurationException;
use RetJetApi\Returns\Http\RequestBuilder;

#[CoversClass(RequestBuilder::class)]
final class RequestBuilderTest extends TestCase
{
    private function builder(string $baseUri = 'https://api.example.com'): RequestBuilder
    {
        $factory = new Psr17Factory();

        return new RequestBuilder(new Configuration('secret-key', $baseUri), $factory, $factory);
    }

    public function testItSendsTheAuthenticationAndNegotiationHeaders(): void
    {
        $request = $this->builder()->build('GET', '/v1/sale-channels');

        self::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
        self::assertSame('application/ld+json', $request->getHeaderLine('Accept'));
        self::assertSame(Configuration::defaultUserAgent(), $request->getHeaderLine('User-Agent'));
        self::assertFalse($request->hasHeader('Content-Type'), 'A bodyless request declares no content type.');
    }

    public function testTheMethodIsUppercased(): void
    {
        self::assertSame('DELETE', $this->builder()->build('delete', '/v1/rma-requests/1/star')->getMethod());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paths(): iterable
    {
        yield 'leading slash' => ['/v1/sale-channels', 'https://api.example.com/v1/sale-channels'];
        yield 'no leading slash' => ['v1/sale-channels', 'https://api.example.com/v1/sale-channels'];
        yield 'absolute url on the base host' => [
            'https://api.example.com/v1/rma-requests?page=2',
            'https://api.example.com/v1/rma-requests?page=2',
        ];
        // Accepted as same-origin; PSR-7 then drops the redundant default port.
        yield 'absolute url with the default port spelled out' => [
            'https://api.example.com:443/v1/rma-requests',
            'https://api.example.com/v1/rma-requests',
        ];
    }

    #[DataProvider('paths')]
    public function testItResolvesThePathAgainstTheBaseUri(string $path, string $expected): void
    {
        self::assertSame($expected, (string) $this->builder()->build('GET', $path)->getUri());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignUrls(): iterable
    {
        yield 'different host' => ['https://evil.example/v1/rma-requests'];
        yield 'different scheme' => ['http://api.example.com/v1/rma-requests'];
        yield 'different port' => ['https://api.example.com:8443/v1/rma-requests'];
        yield 'host as credentials' => ['https://api.example.com@evil.example/v1/rma-requests'];
    }

    /**
     * Every request carries the API key. `view.next` is server-controlled data, so following
     * it off the configured origin would disclose the key to whoever the server names.
     */
    #[DataProvider('foreignUrls')]
    public function testItRefusesToAuthenticateAgainstAForeignOrigin(string $url): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('would be disclosed');

        $this->builder()->build('GET', $url);
    }

    /**
     * parse_str()/http_build_query() rewrite parameter names; the merge must not.
     *
     * @return iterable<string, array{string, array<string, scalar|null>, string}>
     */
    public static function preservedQueries(): iterable
    {
        yield 'dotted name survives' => ['/v1/x?filter.name=a', [], '/v1/x?filter.name=a'];
        // PSR-7 percent-encodes the brackets, but the name stays `tags[]`; the bug being
        // guarded against is parse_str() renumbering it to `tags[0]` and `tags[1]`.
        yield 'repeated brackets survive' => [
            '/v1/x?tags[]=a&tags[]=b',
            [],
            '/v1/x?tags%5B%5D=a&tags%5B%5D=b',
        ];
        yield 'valueless flag survives' => ['/v1/x?flag', [], '/v1/x?flag'];
        yield 'untouched pair kept while another is added' => [
            '/v1/x?filter.name=a',
            ['page' => 2],
            '/v1/x?filter.name=a&page=2',
        ];
        yield 'named parameter replaces the one in the path' => ['/v1/x?page=1', ['page' => 9], '/v1/x?page=9'];
        yield 'null drops the one in the path' => ['/v1/x?page=1&keep=y', ['page' => null], '/v1/x?keep=y'];
    }

    /**
     * @param array<string, scalar|null> $query
     */
    #[DataProvider('preservedQueries')]
    public function testExistingQueryParametersAreNotRewritten(string $path, array $query, string $expected): void
    {
        $uri = (string) $this->builder()->build('GET', $path, $query)->getUri();

        self::assertSame('https://api.example.com' . $expected, $uri);
    }

    public function testTheBaseUriOverrideAppliesToEveryRequest(): void
    {
        $uri = (string) $this->builder('https://api.retjet.com')->build('GET', '/v1/return-points')->getUri();

        self::assertSame('https://api.retjet.com/v1/return-points', $uri);
    }

    public function testItAppendsQueryParameters(): void
    {
        $uri = (string) $this->builder()->build('GET', '/v1/rma-requests', ['page' => 3])->getUri();

        self::assertSame('https://api.example.com/v1/rma-requests?page=3', $uri);
    }

    public function testNullParametersAreDropped(): void
    {
        $uri = (string) $this->builder()->build('GET', '/v1/rma-requests', ['page' => null])->getUri();

        self::assertSame('https://api.example.com/v1/rma-requests', $uri);
    }

    public function testBooleansAreSerialisedAsTrueAndFalse(): void
    {
        $uri = (string) $this->builder()->build('GET', '/v1/x', ['a' => true, 'b' => false])->getUri();

        self::assertSame('https://api.example.com/v1/x?a=true&b=false', $uri);
    }

    /**
     * The paginator follows Hydra `view.next`, which already carries `?page=2`. Passing such
     * a link straight back into the builder has to preserve it.
     */
    public function testAQueryAlreadyPresentInThePathIsPreservedAndOverridable(): void
    {
        $builder = $this->builder();

        self::assertSame(
            'https://api.example.com/v1/rma-requests?page=2',
            (string) $builder->build('GET', '/v1/rma-requests?page=2')->getUri(),
        );

        self::assertSame(
            'https://api.example.com/v1/rma-requests?page=5',
            (string) $builder->build('GET', '/v1/rma-requests?page=2', ['page' => 5])->getUri(),
        );

        self::assertSame(
            'https://api.example.com/v1/rma-requests',
            (string) $builder->build('GET', '/v1/rma-requests?page=2', ['page' => null])->getUri(),
        );
    }

    public function testParametersAreUrlEncoded(): void
    {
        $uri = (string) $this->builder()->build('GET', '/v1/x', ['q' => 'a b&c'])->getUri();

        self::assertSame('https://api.example.com/v1/x?q=a%20b%26c', $uri);
    }

    public function testItEncodesTheBodyAsJson(): void
    {
        $request = $this->builder()->build('POST', '/v1/rma-requests', [], ['comment' => 'zwrot', 'items' => [1, 2]]);

        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"comment":"zwrot","items":[1,2]}', (string) $request->getBody());
    }

    public function testSlashesAndUnicodeAreLeftReadable(): void
    {
        $request = $this->builder()->build('POST', '/v1/x', [], ['url' => 'https://a/b', 'text' => 'zażółć']);

        self::assertSame('{"url":"https://a/b","text":"zażółć"}', (string) $request->getBody());
    }

    /**
     * Several write endpoints take no fields; an empty PHP array must not turn into `[]`,
     * which the API cannot denormalise into a resource.
     */
    public function testAnEmptyBodyIsSentAsAnEmptyJsonObject(): void
    {
        self::assertSame('{}', (string) $this->builder()->build('POST', '/v1/rma-requests/1/star', [], [])->getBody());
    }

    public function testHeaderOverridesReplaceTheDefaultsCaseInsensitively(): void
    {
        $request = $this->builder()->build('GET', '/v1/x', [], null, ['accept' => 'application/json']);

        self::assertSame(['application/json'], $request->getHeader('Accept'));
        self::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
    }

    public function testExtraHeadersAreAdded(): void
    {
        $request = $this->builder()->build('GET', '/v1/x', [], null, ['X-Request-Id' => 'abc']);

        self::assertSame('abc', $request->getHeaderLine('X-Request-Id'));
    }
}
