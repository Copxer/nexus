<?php

namespace App\Domain\AI\DataTransferObjects;

class LlmResponse
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $text,
        public readonly ?string $model = null,
        public readonly array $metadata = [],
    ) {}
}
