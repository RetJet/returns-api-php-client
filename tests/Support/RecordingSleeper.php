<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Support;

/**
 * Stands in for RetryMiddleware's sleep so the suite never actually waits - and so the
 * computed delays can be asserted, not just the number of attempts.
 */
final class RecordingSleeper
{
    /** @var list<float> */
    private array $delays = [];

    public function __invoke(float $seconds): void
    {
        $this->delays[] = $seconds;
    }

    /**
     * @return list<float>
     */
    public function delays(): array
    {
        return $this->delays;
    }

    public function slept(): int
    {
        return count($this->delays);
    }
}
