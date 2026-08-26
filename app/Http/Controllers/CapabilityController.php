<?php

namespace App\Http\Controllers;

use App\Http\Resources\CapabilityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;
use Yutoseta\InterviewEngine\Services\StructuredInterviewStateEngine;

final class CapabilityController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): JsonResource
    {
        return new CapabilityResource([]);
    }

    public function verify(): JsonResponse
    {
        $checks = [
            'contract_installed' => is_file(base_path('vendor/yutoseta/magic-html-contracts/openapi/tier1.json')),
            'interview_engine' => class_exists(StructuredInterviewStateEngine::class),
            'database' => Schema::hasTable('interview_sessions'),
        ];
        $ready = ! in_array(false, $checks, true);

        return response()->json([
            'service' => 'magic-html-interview-service',
            'tier' => 1,
            'status' => $ready ? 'ok' : 'degraded',
            'contract_version' => '1.0',
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }
}
