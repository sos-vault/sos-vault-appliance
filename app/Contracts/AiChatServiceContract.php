<?php

namespace App\Contracts;

use App\Exceptions\AiProviderException;
use App\Exceptions\AiRateLimitException;

interface AiChatServiceContract
{
    /**
     * Send a user message and return the assistant's Markdown-formatted response.
     *
     * @param  string  $userMessage  Raw text from the user
     * @param  array<int, array{role: string, content: string}>  $history  Prior conversation turns
     * @param  int|null  $caseDirectoryId  The directory id (did) — if set, injects case JSON context
     * @param  int|null  $caseId  The case id (cid) — for audit/logging only
     * @param  int  $userId  Used for rate limiting and token tracking
     * @return string Markdown-formatted response text
     *
     * @throws AiRateLimitException
     * @throws AiProviderException
     */
    public function chat(
        string $userMessage,
        array $history,
        ?int $caseDirectoryId,
        ?int $caseId,
        int $userId,
    ): string;

    public function providerName(): string;
}
