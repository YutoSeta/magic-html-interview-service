<?php

namespace App\Exceptions;

use App\Support\Problem;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class IdempotencyConflict extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('The Idempotency-Key was already used for a different request in this scope.');
    }

    public function render(Request $request): JsonResponse
    {
        return Problem::response($request, 409, 'idempotency_conflict', $this->getMessage());
    }
}
