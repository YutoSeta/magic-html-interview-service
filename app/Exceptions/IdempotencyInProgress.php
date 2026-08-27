<?php

namespace App\Exceptions;

use App\Support\Problem;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class IdempotencyInProgress extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('The idempotent operation is still being processed.');
    }

    public function render(Request $request): JsonResponse
    {
        return Problem::response($request, 409, 'idempotency_in_progress', $this->getMessage());
    }
}
