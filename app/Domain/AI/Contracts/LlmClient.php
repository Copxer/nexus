<?php

namespace App\Domain\AI\Contracts;

use App\Domain\AI\DataTransferObjects\LlmPrompt;
use App\Domain\AI\DataTransferObjects\LlmResponse;

interface LlmClient
{
    public function complete(LlmPrompt $prompt): LlmResponse;
}
