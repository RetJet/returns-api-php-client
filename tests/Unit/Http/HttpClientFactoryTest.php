<?php

declare(strict_types=1);

// Overrides the global extension_loaded() as seen from RetJetApi\Returns\Http: PHP resolves an
// unqualified function call against the current namespace before falling back to the global
// one, so this lets testMissingCurlExtensionDoesNotProduceAPhpError() below simulate an
// installation with php-http/curl-client present but ext-curl absent - a combination
// composer cannot express, since the dependency is on the userland package, not the
// extension, and no other file in this namespace calls extension_loaded().

namespace RetJetApi\Returns\Http {
    function extension_loaded(string $name): bool
    {
        return \RetJetApi\Returns\Tests\Unit\Http\HttpClientFactoryTest::$curlExtensionLoaded
            && \extension_loaded($name);
    }
}

namespace RetJetApi\Returns\Tests\Unit\Http {

    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;
    use Psr\Http\Client\ClientInterface;
    use RetJetApi\Returns\Exception\ConfigurationException;
    use RetJetApi\Returns\Http\HttpClientFactory;

    #[CoversClass(HttpClientFactory::class)]
    final class HttpClientFactoryTest extends TestCase
    {
        public static bool $curlExtensionLoaded = true;

        protected function tearDown(): void
        {
            self::$curlExtensionLoaded = true;
        }

        /**
         * php-http/curl-client is a dev dependency of this package, so a configurable client is
         * always available in the test suite.
         */
        public function testAConfigurableClientIsAvailableHere(): void
        {
            self::assertTrue(HttpClientFactory::supportsTimeout());
        }

        public function testItBuildsAPsr18Client(): void
        {
            self::assertInstanceOf(ClientInterface::class, HttpClientFactory::create(10));
        }

        /**
         * An explicitly requested timeout only survives if the factory can apply it; here it can,
         * so the request must not be refused.
         */
        public function testAnExplicitTimeoutIsAcceptedWhenItCanBeApplied(): void
        {
            self::assertInstanceOf(ClientInterface::class, HttpClientFactory::create(30, true));
        }

        public function testEachCallBuildsItsOwnClient(): void
        {
            self::assertNotSame(HttpClientFactory::create(10), HttpClientFactory::create(10));
        }

        /**
         * php-http/curl-client is loadable from the Composer autoloader regardless of whether
         * ext-curl is actually installed - it is a userland package, not the extension itself.
         * Without the extension_loaded() guard in curl(), instantiating CURLOPT_TIMEOUT crashes
         * with a PHP Error rather than the ConfigurationException this method promises.
         */
        public function testMissingCurlExtensionDoesNotProduceAPhpError(): void
        {
            self::$curlExtensionLoaded = false;

            self::assertFalse(HttpClientFactory::supportsTimeout());
        }

        public function testMissingCurlExtensionFailsTimeoutRequestsWithConfigurationException(): void
        {
            self::$curlExtensionLoaded = false;

            $this->expectException(ConfigurationException::class);

            HttpClientFactory::create(10, true);
        }
    }
}
