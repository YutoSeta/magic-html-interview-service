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
            ],
            'write_safety' => [
                'idempotency_key' => [
                    'header' => 'Idempotency-Key',
                    'minimum_length' => 8,
                    'maximum_length' => 200,
                    'required_for' => ['start', 'answer'],
                    'optional_for' => ['import'],
                    'scope' => [
                        'start' => ['site_id'],
                        'answer' => ['site_id', 'interview_session_id'],
                    ],
                ],
                'answer_precondition' => [
                    'field' => 'expected_step',
                    'conflict_status' => 409,
                    'session_serialization' => 'atomic_lock',
                ],
            ],
        ];
    }
}
