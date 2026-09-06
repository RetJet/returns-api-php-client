<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Exception;

/**
 * The API rejected the credentials with 401. Note that an *invalid* key currently yields a 500 instead - see ServerException.
 */
final class AuthenticationException extends ApiException
{
}
