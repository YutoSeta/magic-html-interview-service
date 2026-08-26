<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AdvanceInterview;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnswerInterviewRequest;
use App\Http\Requests\ImportInterviewRequest;
use App\Http\Requests\StartInterviewRequest;
use App\Http\Resources\InterviewSessionResource;
use App\Models\InterviewSession;
use App\Support\CanonicalJson;
use App\Support\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InterviewController extends Controller
{
    public function store(StartInterviewRequest $request, AdvanceInterview $advance): JsonResponse
    {
        $session = InterviewSession::query()->create([
            'site_id' => $request->validated('site_id'),
            'locale' => $request->validated('locale'),
            'status' => InterviewSession::STATUS_ACTIVE,
            'current_step' => 0,
            'messages' => $advance->initialMessages($request->validated('locale')),
            'structured_data' => [],
        ]);

        return (new InterviewSessionResource($session))->response()->setStatusCode(201);
    }

    public function import(ImportInterviewRequest $request): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        $keyHash = is_string($key) && $key !== '' ? hash('sha256', $key) : null;
        $requestHash = hash('sha256', CanonicalJson::encode([
            'site_id' => $request->validated('site_id'),
            'locale' => $request->validated('locale'),
            'interview' => $request->validated('interview'),
        ]));
        if ($keyHash !== null) {
            $existing = InterviewSession::query()->where('idempotency_key_hash', $keyHash)->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                    return Problem::response($request, 409, 'idempotency_conflict', 'The Idempotency-Key was already used for a different interview.');
                }

                return (new InterviewSessionResource($existing))->response()->setStatusCode(200);
            }
        }

        $session = InterviewSession::query()->create([
            'site_id' => $request->validated('site_id'),
            'locale' => $request->validated('locale'),
            'status' => InterviewSession::STATUS_COMPLETED,
            'current_step' => 6,
            'messages' => [],
            'structured_data' => $request->validated('interview'),
            'idempotency_key_hash' => $keyHash,
            'request_hash' => $requestHash,
        ]);

        return (new InterviewSessionResource($session))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $interview): InterviewSessionResource|JsonResponse
    {
        $session = InterviewSession::query()->find($interview);

        return $session === null
            ? Problem::response($request, 404, 'interview_not_found', 'The interview session was not found.')
            : new InterviewSessionResource($session);
    }

    public function answer(
        AnswerInterviewRequest $request,
        string $interview,
        AdvanceInterview $advance,
    ): InterviewSessionResource|JsonResponse {
        $session = InterviewSession::query()->find($interview);
        if ($session === null) {
            return Problem::response($request, 404, 'interview_not_found', 'The interview session was not found.');
        }
        if ($session->status !== InterviewSession::STATUS_ACTIVE) {
            return Problem::response($request, 409, 'interview_completed', 'The interview session is already complete.');
        }

        return new InterviewSessionResource($advance->execute($session, $request->validated('answer')));
    }

    public function destroy(Request $request, string $interview): JsonResponse
    {
        $session = InterviewSession::query()->find($interview);
        if ($session === null) {
            return Problem::response($request, 404, 'interview_not_found', 'The interview session was not found.');
        }
        $session->delete();

        return response()->json(status: 204);
    }
}
