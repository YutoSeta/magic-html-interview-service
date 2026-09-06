<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\UpsertIntakeTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertIntakeTemplateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class IntakeTemplateController extends Controller
{
    public function upsert(
        UpsertIntakeTemplateRequest $request,
        string $template,
        UpsertIntakeTemplate $upsertTemplate,
    ): JsonResponse {
        $result = $upsertTemplate->execute(
            $template,
            $request->validated(),
            (string) ($request->header('X-Request-Id') ?: Str::uuid()),
        );

        return response()->json($result->body, $result->status);
    }
}
