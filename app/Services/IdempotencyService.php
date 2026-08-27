<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflict;
use App\Exceptions\IdempotencyInProgress;
use App\Models\InterviewIdempotencyRecord;
use App\Support\CanonicalJson;
use App\Support\OperationResult;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class IdempotencyService
{
    /**
     * @param  array<string,string>  $scope
     * @param  array<string,mixed>  $fingerprint
     * @param  Closure():OperationResult  $operationCallback
     */
    public function execute(
        string $idempotencyKey,
        string $operation,
        array $scope,
        array $fingerprint,
        Closure $operationCallback,
    ): OperationResult {
        $scopedKeyHash = hash('sha256', CanonicalJson::encode([
            'scope' => $scope,
            'key' => $idempotencyKey,
        ]));
        $requestHash = hash('sha256', CanonicalJson::encode($fingerprint));

        try {
            return Cache::lock(
                "interview-idempotency:{$scopedKeyHash}",
                (int) config('interview.idempotency.lock_seconds'),
            )->block(
                (int) config('interview.idempotency.wait_seconds'),
                fn (): OperationResult => DB::transaction(
                    fn (): OperationResult => $this->executeWithinTransaction(
                        $scopedKeyHash,
                        $requestHash,
                        $operation,
                        $operationCallback,
                    ),
                    3,
                ),
            );
        } catch (LockTimeoutException) {
            throw new IdempotencyInProgress;
        }
    }

    /** @param Closure():OperationResult $operationCallback */
    private function executeWithinTransaction(
        string $scopedKeyHash,
        string $requestHash,
        string $operation,
        Closure $operationCallback,
    ): OperationResult {
        $record = InterviewIdempotencyRecord::query()->lockForUpdate()->find($scopedKeyHash);
        if ($record !== null) {
            if (! hash_equals($record->request_hash, $requestHash) || $record->operation !== $operation) {
                throw new IdempotencyConflict;
            }
            if ($record->response_status === null || $record->response_body === null) {
                throw new IdempotencyInProgress;
            }

            return new OperationResult(
                $record->response_status,
                $record->response_body,
                $record->interview_session_id,
                true,
            );
        }

        $record = InterviewIdempotencyRecord::query()->create([
            'scoped_key_hash' => $scopedKeyHash,
            'request_hash' => $requestHash,
            'operation' => $operation,
            'created_at' => now(),
        ]);
        $result = $operationCallback();
        $record->update([
            'interview_session_id' => $result->interviewSessionId,
            'response_status' => $result->status,
            'response_body' => $result->body,
            'completed_at' => now(),
        ]);

        return $result;
    }
}
