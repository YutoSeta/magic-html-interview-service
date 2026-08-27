<?php

namespace App\Actions;

use App\Http\Resources\InterviewSessionResource;
use App\Models\InterviewSession;
use App\Services\IdempotencyService;
use App\Support\OperationResult;

final class StartInterviewSession
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AdvanceInterview $advanceInterview,
    ) {}

    /** @param array{contract_version:string,site_id:string,locale:string} $payload */
    public function execute(array $payload, string $idempotencyKey): OperationResult
    {
        return $this->idempotency->execute(
            $idempotencyKey,
            'interview.start',
            ['site_id' => $payload['site_id']],
            $payload,
            function () use ($payload): OperationResult {
                $session = InterviewSession::query()->create([
                    'site_id' => $payload['site_id'],
                    'locale' => $payload['locale'],
                    'status' => InterviewSession::STATUS_ACTIVE,
                    'current_step' => 0,
                    'messages' => $this->advanceInterview->initialMessages($payload['locale']),
                    'structured_data' => [],
                ]);

                return new OperationResult(
                    201,
                    InterviewSessionResource::present($session),
                    $session->id,
                );
            },
        );
    }
}
