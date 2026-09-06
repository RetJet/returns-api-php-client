<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Exception;

/**
 * The API failed while handling the request (5xx).
 *
 * Also the exception that can still surface for an invalid API key: the server exchanges the
 * key for a JWT on every request, and when that exchange itself fails - rather than simply
 * rejecting a bad key - it answers 500 with detail "Unable to exchange token" instead of the
 * 401 a bad key normally produces. The SDK deliberately does not rewrite that into an
 * AuthenticationException - matching on message text would be guesswork - so read
 * problem()->detail() to tell a bad key from a real outage.
 */
final class ServerException extends ApiException
{
}
