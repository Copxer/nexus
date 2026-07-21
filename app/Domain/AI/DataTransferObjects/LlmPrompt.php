<?php

namespace App\Domain\AI\DataTransferObjects;

class LlmPrompt
{
    public function __construct(
        public readonly string $version,
        public readonly string $system,
        public readonly string $user,
        public readonly int $maxTokens = 1_200,
    ) {}
}
