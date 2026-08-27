<?php

namespace App\Support;

final readonly class OperationResult
{
    /** @param array<string,mixed> $body */
    public function __construct(
        public int $status,
        public array $body,
        public ?string $interviewSessionId,
        public bool $replayed = false,
    ) {}
}
