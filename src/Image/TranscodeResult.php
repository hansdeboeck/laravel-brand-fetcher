<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Image;

/** Het omgezette beeld plus wat we onderweg over de bron te weten kwamen. */
final class TranscodeResult
{
    public function __construct(
        public readonly string $bytes,
        public readonly int $sourceWidth,
        public readonly int $sourceHeight,
        public readonly float $sourceRatio,
        /** Of de BRON doorzichtigheid had. Na het opvullen toont de uitvoer die niet meer. */
        public readonly bool $hasAlpha,
        public readonly bool $trimmed,
        public readonly bool $lossless,
    ) {}
}
