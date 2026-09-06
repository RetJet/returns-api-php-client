<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Exception;

use Throwable;

/**
 * Marker interface implemented by every exception this SDK throws.
 *
 * A single `catch (RetJetException $e)` therefore covers the whole library, regardless of
 * whether the failure came from the network, the API or the local configuration.
 */
interface RetJetException extends Throwable
{
}
