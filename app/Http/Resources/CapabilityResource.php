<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CapabilityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => 'magic-html-interview-service',
            'tier' => 1,
            'contract_version' => '1.0',
            'documentation' => url('/api/__verify'),
            'health' => url('/up'),
            'operations' => [
                'POST /api/v1/interviews',
                'POST /api/v1/interviews/import',
                'GET /api/v1/interviews/{interview}',
                'POST /api/v1/interviews/{interview}/messages',
                'DELETE /api/v1/interviews/{interview}',
                'PUT /api/v1/intake-templates/{template}',
                'POST /api/v1/intake-templates/{template}/sessions',
                'GET /api/v1/intake-sessions/{interview}',
            ],
            'write_safety' => [
                'idempotency_key' => [
                    'header' => 'Idempotency-Key',
                    'minimum_length' => 8,
                    'maximum_length' => 200,
                    'required_for' => ['start', 'answer', 'start_intake_session'],
                    'optional_for' => ['import'],
                    'scope' => [
                        'start' => ['site_id'],
                        'answer' => ['site_id', 'interview_session_id'],
                        'start_intake_session' => ['template'],
                    ],
                ],
                'answer_precondition' => [
                    'field' => 'expected_step',
                    'conflict_status' => 409,
                    'session_serialization' => 'atomic_lock',
                ],
                'intake_browser_access' => [
                    'entry' => 'temporary_signed_url',
                    'authorization' => 'browser_session_and_interview_uuid',
                    'modes' => ['chat', 'form'],
                ],
            ],
        ];
    }
}
