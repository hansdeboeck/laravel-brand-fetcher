<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Discovery;

/** Een mogelijk logo: een url plus wat de pagina erover beweerde. */
final class IconCandidate
{
    public function __construct(
        public readonly string $url,
        public readonly string $source,
        /** Wat het sizes-attribuut of het manifest beweert. Een bewering, geen meting. */
        public readonly ?int $declaredSize = null,
        /**
         * De vorm die daarbij beweerd werd: de langste zijde gedeeld door de
         * kortste, dus altijd 1 of meer. Null als de bron er niets over zei.
         */
        public readonly ?float $declaredRatio = null,
        public readonly ?string $mime = null,
        /** Een maskable icoon wordt door het toestel aan de randen afgesneden. */
        public readonly bool $maskable = false,
        public readonly ?int $measuredWidth = null,
        public readonly ?int $measuredHeight = null,
        public readonly bool $hasAlpha = false,
    ) {}

    public function measured(int $width, int $height, bool $hasAlpha): self
    {
        return new self(
            url: $this->url,
            source: $this->source,
            declaredSize: $this->declaredSize,
            declaredRatio: $this->declaredRatio,
            mime: $this->mime,
            maskable: $this->maskable,
            measuredWidth: $width,
            measuredHeight: $height,
            hasAlpha: $hasAlpha,
        );
    }

    public function isSvg(): bool
    {
        return $this->mime === 'image/svg+xml'
            || preg_match('/\.svgz?($|\?)/i', $this->url) === 1;
    }
}
