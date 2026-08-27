<?php

namespace App\Actions;

use App\Exceptions\IdempotencyInProgress;
use App\Http\Resources\InterviewSessionResource;
use App\Models\InterviewSession;
use App\Services\IdempotencyService;
use App\Support\CanonicalJson;
use App\Support\OperationResult;
use App\Support\Problem;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class RecordInterviewAnswer
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AdvanceInterview $advanceInterview,
    ) {}

    /** @param array{contract_version:string,expected_step:int,answer:string} $payload */
    public function execute(
        InterviewSession $session,
        array $payload,
        string $idempotencyKey,
        string $requestId,
    ): OperationResult {
        $scope = ['site_id' => $session->site_id, 'interview_session_id' => $session->id];
        $sessionScopeHash = hash('sha256', CanonicalJson::encode($scope));

        try {
            return Cache::lock(
                "interview-session:{$sessionScopeHash}",
                (int) config('interview.idempotency.lock_seconds'),
            )->block(
                (int) config('interview.idempotency.wait_seconds'),
                fn (): OperationResult => $this->idempotency->execute(
                    $idempotencyKey,
                    'interview.answer',
                    $scope,
                    $payload,
                    fn (): OperationResult => $this->record($session, $payload, $requestId),
                ),
            );
        } catch (LockTimeoutException) {
            throw new IdempotencyInProgress;
        }
    }

    /** @param array{contract_version:string,expected_step:int,answer:string} $payload */
    private function record(InterviewSession $session, array $payload, string $requestId): OperationResult
    {
        $locked = InterviewSession::query()->lockForUpdate()->find($session->id);
        if ($locked === null) {
            return new OperationResult(404, Problem::body(
                404,
                'interview_not_found',
                'The interview session was not found.',
                $requestId,
            ), null);
        }
        if ($payload['expected_step'] !== $locked->current_step) {
            return new OperationResult(409, Problem::body(
                409,
                'interview_step_conflict',
                "The expected step does not match the current interview step ({$locked->current_step}).",
                $requestId,
            ), $locked->id);
        }
        if ($locked->status !== InterviewSession::STATUS_ACTIVE) {
            return new OperationResult(409, Problem::body(
                409,
                'interview_completed',
                'The interview session is already complete.',
                $requestId,
            ), $locked->id);
        }

        $advanced = $this->advanceInterview->executeLocked($locked, $payload['answer']);

        return new OperationResult(
            200,
            InterviewSessionResource::present($advanced),
            $advanced->id,
        );
    }
}
