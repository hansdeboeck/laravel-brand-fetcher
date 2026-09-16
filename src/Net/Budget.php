<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Net;

/**
 * De wandklok van een ophaling.
 *
 * hrtime en niet microtime: dit moet een verstreken duur meten, en een klok die
 * tijdens de meting verzet kan worden is daar het verkeerde gereedschap voor.
 */
final class Budget
{
    private readonly float $startedAt;

    public function __construct(private readonly int $limitMs)
    {
        $this->startedAt = (float) hrtime(true);
    }

    public function elapsedMs(): float
    {
        return ((float) hrtime(true) - $this->startedAt) / 1_000_000;
    }

    public function remainingMs(): float
    {
        return max(0.0, $this->limitMs - $this->elapsedMs());
    }

    public function remainingSeconds(): float
    {
        return $this->remainingMs() / 1000;
    }

    public function exhausted(): bool
    {
        return $this->remainingMs() <= 0;
    }

    /**
     * Is er nog genoeg tijd voor een fase van deze lengte? Onder een halve
     * seconde heeft een nieuwe verbinding geen zin meer: de opbouw alleen al
     * eet dat op, en dan hebben we wel gewacht maar niets gekregen.
     */
    public function allows(float $phaseSeconds): bool
    {
        return $this->remainingSeconds() >= min($phaseSeconds, 0.5);
    }

    /** De kortste van wat de fase vraagt en wat er nog over is. */
    public function clamp(float $phaseSeconds): float
    {
        return max(0.1, min($phaseSeconds, $this->remainingSeconds()));
    }
}
