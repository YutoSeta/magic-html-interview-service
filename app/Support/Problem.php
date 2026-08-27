<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class Problem
{
    /** @param array<string,mixed> $errors */
    public static function response(Request $request, int $status, string $type, string $detail, array $errors = []): JsonResponse
    {
        return response()->json(self::body(
            $status,
            $type,
            $detail,
            (string) ($request->header('X-Request-Id') ?: str()->uuid()),
            $errors,
        ), $status);
    }

    /**
     * @param  array<string,mixed>  $errors
     * @return array<string,mixed>
     */
    public static function body(int $status, string $type, string $detail, string $requestId, array $errors = []): array
    {
        $body = [
            'contract_version' => '1.0',
            'type' => $type,
            'title' => Response::$statusTexts[$status],
            'status' => $status,
            'detail' => $detail,
            'request_id' => $requestId,
        ];
        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return $body;
    }
}
