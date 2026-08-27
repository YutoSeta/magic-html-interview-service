<?php

namespace Tests\Feature;

use App\Models\InterviewSession;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class InterviewControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('interview.service_token', 'test-token');
    }

    public function test_chat_produces_a_structured_brief_after_six_answers(): void
    {
        $start = $this->withToken('test-token')->withHeader('Idempotency-Key', 'start-flow-0001')->postJson('/api/v1/interviews', [
            'contract_version' => '1.0',
            'site_id' => 'site-one',
            'locale' => 'ja',
        ])->assertCreated()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('next_question.field', 'organization');

        $id = $start->json('id');
        foreach (['Web工房', '問い合わせ増加', '中小企業', '誠実で明快', '5ページ', "https://example.com\n資料なし"] as $index => $answer) {
            $response = $this->withToken('test-token')->withHeader('Idempotency-Key', "answer-flow-000{$index}")->postJson("/api/v1/interviews/{$id}/messages", [
                'contract_version' => '1.0',
                'expected_step' => $index,
                'answer' => $answer,
            ])->assertOk();
            if ($index < 5) {
                $response->assertJsonPath('status', 'active');
            }
        }

        $response->assertJsonPath('status', 'completed')
            ->assertJsonPath('structured_data.organization', 'Web工房')
            ->assertJsonPath('structured_data.materials.0', 'https://example.com');
        $this->withToken('test-token')->withHeader('Idempotency-Key', 'answer-extra-0001')->postJson("/api/v1/interviews/{$id}/messages", [
            'contract_version' => '1.0',
            'expected_step' => 6,
            'answer' => 'extra',
        ])->assertConflict()
            ->assertJsonPath('type', 'interview_completed');
    }

    public function test_existing_brief_can_be_imported_for_orchestration(): void
    {
        $payload = [
            'contract_version' => '1.0',
            'site_id' => 'site-one',
            'locale' => 'ja',
            'interview' => [
                'organization' => 'Web工房',
                'goals' => '問い合わせ増加',
                'audience' => '中小企業',
                'tone' => '誠実',
                'requirements' => '5ページ',
                'materials' => [],
            ],
        ];
        $first = $this->withToken('test-token')->withHeader('Idempotency-Key', 'site:job:interview')
            ->postJson('/api/v1/interviews/import', $payload)->assertCreated()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('structured_data.goals', '問い合わせ増加');
        $this->withToken('test-token')->withHeader('Idempotency-Key', 'site:job:interview')
            ->postJson('/api/v1/interviews/import', $payload)->assertOk()
            ->assertJsonPath('id', $first->json('id'));
        $payload['interview']['goals'] = 'different';
        $this->withToken('test-token')->withHeader('Idempotency-Key', 'site:job:interview')
            ->postJson('/api/v1/interviews/import', $payload)->assertConflict()
            ->assertJsonPath('type', 'idempotency_conflict');
        $this->assertDatabaseCount('interview_sessions', 1);
        $this->assertDatabaseEmpty('interview_idempotency_records');
    }

    public function test_authentication_and_session_deletion_are_enforced(): void
    {
        $this->postJson('/api/v1/interviews', [
            'contract_version' => '1.0',
            'site_id' => 'site-one',
        ])->assertUnauthorized();

        $start = $this->withToken('test-token')->withHeader('Idempotency-Key', 'start-delete-0001')->postJson('/api/v1/interviews', [
            'contract_version' => '1.0',
            'site_id' => 'site-one',
        ])->assertCreated();
        $this->withToken('test-token')->deleteJson('/api/v1/interviews/'.$start->json('id'))->assertNoContent();
        $this->assertDatabaseEmpty('interview_sessions');
    }

    public function test_capability_verification_includes_the_shared_interview_engine(): void
    {
        $this->getJson('/api/__verify')
            ->assertOk()
            ->assertJsonPath('checks.contract_installed', true)
            ->assertJsonPath('checks.contract_schemas', true)
            ->assertJsonPath('checks.contract_operations', true)
            ->assertJsonPath('checks.interview_engine', true);

        $this->getJson('/api')
            ->assertOk()
            ->assertJsonPath('write_safety.idempotency_key.required_for.0', 'start')
            ->assertJsonPath('write_safety.idempotency_key.required_for.1', 'answer')
            ->assertJsonPath('write_safety.answer_precondition.field', 'expected_step');

        $this->get('/interviews')->assertNotFound();
    }

    public function test_capability_verification_fails_when_the_interview_contract_inventory_is_unavailable(): void
    {
        config()->set('interview.contracts_root', base_path('missing-interview-contracts'));

        $this->getJson('/api/__verify')
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.contract_installed', false)
            ->assertJsonPath('checks.contract_schemas', false)
            ->assertJsonPath('checks.contract_operations', false)
            ->assertJsonPath('checks.interview_engine', true)
            ->assertJsonPath('checks.database', true);
    }

    public function test_capability_verification_fails_when_answer_step_precondition_contract_is_stale(): void
    {
        $contractsRoot = $this->temporaryContractsSnapshot();
        $answerPath = $contractsRoot.'/schemas/v1/interview/answer-request.json';
        $answer = json_decode(File::get($answerPath), true, flags: JSON_THROW_ON_ERROR);
        $answer['required'] = ['contract_version', 'answer'];
        unset($answer['properties']['expected_step']);
        File::put($answerPath, json_encode($answer, JSON_THROW_ON_ERROR));
        config()->set('interview.contracts_root', $contractsRoot);

        try {
            $this->getJson('/api/__verify')
                ->assertServiceUnavailable()
                ->assertJsonPath('checks.contract_schemas', false)
                ->assertJsonPath('checks.contract_operations', true);
        } finally {
            File::deleteDirectory($contractsRoot);
        }
    }

    public function test_capability_verification_fails_when_write_idempotency_contract_is_stale(): void
    {
        $contractsRoot = $this->temporaryContractsSnapshot();
        $openApiPath = $contractsRoot.'/openapi/tier1.json';
        $openApi = json_decode(File::get($openApiPath), true, flags: JSON_THROW_ON_ERROR);
        unset($openApi['paths']['/v1/interviews']['post']['parameters']);
        File::put($openApiPath, json_encode($openApi, JSON_THROW_ON_ERROR));
        config()->set('interview.contracts_root', $contractsRoot);

        try {
            $this->getJson('/api/__verify')
                ->assertServiceUnavailable()
                ->assertJsonPath('checks.contract_schemas', true)
                ->assertJsonPath('checks.contract_operations', false);
        } finally {
            File::deleteDirectory($contractsRoot);
        }
    }

    public function test_start_retry_returns_the_original_201_response_and_changed_input_returns_409(): void
    {
        $payload = [
            'contract_version' => '1.0',
            'site_id' => 'site-retry',
            'locale' => 'ja',
        ];
        $first = $this->withToken('test-token')
            ->withHeaders(['Idempotency-Key' => 'start-retry-0001', 'X-Request-Id' => 'start-first'])
            ->postJson('/api/v1/interviews', $payload)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'false');

        $retry = $this->withToken('test-token')
            ->withHeaders(['Idempotency-Key' => 'start-retry-0001', 'X-Request-Id' => 'start-retry'])
            ->postJson('/api/v1/interviews', $payload)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $retry->json());
        $payload['locale'] = 'en';
        $this->withToken('test-token')->withHeader('Idempotency-Key', 'start-retry-0001')
            ->postJson('/api/v1/interviews', $payload)
            ->assertConflict()
            ->assertJsonPath('type', 'idempotency_conflict');
        $this->assertDatabaseCount('interview_sessions', 1);
        $this->assertDatabaseCount('interview_idempotency_records', 1);
    }

    public function test_answer_retry_returns_the_original_response_without_advancing_twice(): void
    {
        $sessionId = $this->startInterview('site-answer-retry', 'start-answer-retry')->json('id');
        $payload = [
            'contract_version' => '1.0',
            'expected_step' => 0,
            'answer' => 'Web工房',
        ];
        $first = $this->withToken('test-token')
            ->withHeaders(['Idempotency-Key' => 'answer-retry-0001', 'X-Request-Id' => 'answer-first'])
            ->postJson("/api/v1/interviews/{$sessionId}/messages", $payload)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('current_step', 1);

        $retry = $this->withToken('test-token')
            ->withHeaders(['Idempotency-Key' => 'answer-retry-0001', 'X-Request-Id' => 'answer-retry'])
            ->postJson("/api/v1/interviews/{$sessionId}/messages", $payload)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $retry->json());
        $payload['answer'] = 'Changed';
        $this->withToken('test-token')->withHeader('Idempotency-Key', 'answer-retry-0001')
            ->postJson("/api/v1/interviews/{$sessionId}/messages", $payload)
            ->assertConflict()
            ->assertJsonPath('type', 'idempotency_conflict');
        $session = InterviewSession::query()->findOrFail($sessionId);
        $this->assertSame(1, $session->current_step);
        $this->assertSame('Web工房', $session->structured_data['organization']);
        $this->assertCount(3, $session->messages);
        $this->assertDatabaseCount('interview_idempotency_records', 2);
    }

    public function test_stale_expected_step_returns_409_and_never_replays_an_old_answer_into_the_next_field(): void
    {
        $sessionId = $this->startInterview('site-step-guard', 'start-step-guard')->json('id');
        $this->withToken('test-token')->withHeader('Idempotency-Key', 'answer-step-zero')
            ->postJson("/api/v1/interviews/{$sessionId}/messages", [
                'contract_version' => '1.0',
                'expected_step' => 0,
                'answer' => 'Web工房',
            ])->assertOk();
        $stalePayload = [
            'contract_version' => '1.0',
            'expected_step' => 0,
            'answer' => '問い合わせ増加',
        ];
        $stale = $this->withToken('test-token')->withHeaders([
            'Idempotency-Key' => 'answer-stale-0001',
            'X-Request-Id' => 'stale-first',
        ])->postJson("/api/v1/interviews/{$sessionId}/messages", $stalePayload)
            ->assertConflict()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('type', 'interview_step_conflict');

        $this->withToken('test-token')->withHeader('Idempotency-Key', 'answer-step-one')
            ->postJson("/api/v1/interviews/{$sessionId}/messages", [
                'contract_version' => '1.0',
                'expected_step' => 1,
                'answer' => '問い合わせ増加',
            ])->assertOk()->assertJsonPath('current_step', 2);
        $staleRetry = $this->withToken('test-token')->withHeaders([
            'Idempotency-Key' => 'answer-stale-0001',
            'X-Request-Id' => 'stale-retry',
        ])->postJson("/api/v1/interviews/{$sessionId}/messages", $stalePayload)
            ->assertConflict()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($stale->json(), $staleRetry->json());
        $session = InterviewSession::query()->findOrFail($sessionId);
        $this->assertSame(2, $session->current_step);
        $this->assertSame('問い合わせ増加', $session->structured_data['goals']);
        $this->assertCount(5, $session->messages);
    }

    public function test_same_idempotency_keys_are_isolated_by_site_and_session_scope(): void
    {
        $firstSession = $this->startInterview('scope-site-one', 'shared-start-key')->json('id');
        $secondSession = $this->startInterview('scope-site-two', 'shared-start-key')->json('id');
        $payload = [
            'contract_version' => '1.0',
            'expected_step' => 0,
            'answer' => 'Scoped answer',
        ];

        $this->withToken('test-token')->withHeader('Idempotency-Key', 'shared-answer-key')
            ->postJson("/api/v1/interviews/{$firstSession}/messages", $payload)
            ->assertOk()->assertJsonPath('current_step', 1);
        $this->withToken('test-token')->withHeader('Idempotency-Key', 'shared-answer-key')
            ->postJson("/api/v1/interviews/{$secondSession}/messages", $payload)
            ->assertOk()->assertJsonPath('current_step', 1);

        $this->assertNotSame($firstSession, $secondSession);
        $this->assertDatabaseCount('interview_sessions', 2);
        $this->assertDatabaseCount('interview_idempotency_records', 4);
    }

    public function test_active_atomic_lock_returns_409_without_creating_a_session(): void
    {
        config()->set('interview.idempotency.wait_seconds', 0);
        $idempotencyKey = 'locked-start-key';
        $scopedKeyHash = hash('sha256', CanonicalJson::encode([
            'scope' => ['site_id' => 'locked-site'],
            'key' => $idempotencyKey,
        ]));
        $lock = Cache::lock("interview-idempotency:{$scopedKeyHash}", 30);
        $this->assertTrue($lock->get());

        try {
            $this->withToken('test-token')->withHeader('Idempotency-Key', $idempotencyKey)
                ->postJson('/api/v1/interviews', [
                    'contract_version' => '1.0',
                    'site_id' => 'locked-site',
                ])->assertConflict()
                ->assertJsonPath('type', 'idempotency_in_progress');
        } finally {
            $lock->release();
        }

        $this->assertDatabaseEmpty('interview_sessions');
        $this->assertDatabaseEmpty('interview_idempotency_records');
    }

    public function test_active_session_lock_returns_409_without_advancing_an_answer(): void
    {
        config()->set('interview.idempotency.wait_seconds', 0);
        $sessionId = $this->startInterview('locked-answer-site', 'start-locked-answer')->json('id');
        $sessionScopeHash = hash('sha256', CanonicalJson::encode([
            'site_id' => 'locked-answer-site',
            'interview_session_id' => $sessionId,
        ]));
        $lock = Cache::lock("interview-session:{$sessionScopeHash}", 30);
        $this->assertTrue($lock->get());

        try {
            $this->withToken('test-token')->withHeader('Idempotency-Key', 'locked-answer-key')
                ->postJson("/api/v1/interviews/{$sessionId}/messages", [
                    'contract_version' => '1.0',
                    'expected_step' => 0,
                    'answer' => 'Must not be recorded',
                ])->assertConflict()
                ->assertJsonPath('type', 'idempotency_in_progress');
        } finally {
            $lock->release();
        }

        $session = InterviewSession::query()->findOrFail($sessionId);
        $this->assertSame(0, $session->current_step);
        $this->assertSame([], $session->structured_data);
        $this->assertCount(1, $session->messages);
        $this->assertDatabaseCount('interview_idempotency_records', 1);
    }

    #[DataProvider('invalidIdempotencyKeys')]
    public function test_start_returns_422_for_missing_or_out_of_bounds_idempotency_key(?string $idempotencyKey): void
    {
        $request = $this->withToken('test-token');
        if ($idempotencyKey !== null) {
            $request->withHeader('Idempotency-Key', $idempotencyKey);
        }

        $request->postJson('/api/v1/interviews', [
            'contract_version' => '1.0',
            'site_id' => 'site-key-bounds',
        ])->assertUnprocessable()
            ->assertJsonPath('type', 'validation_failed')
            ->assertJsonValidationErrors('Idempotency-Key');
        $this->assertDatabaseEmpty('interview_sessions');
    }

    /** @return array<string,array{0:?string}> */
    public static function invalidIdempotencyKeys(): array
    {
        return [
            'missing' => [null],
            'seven characters' => ['1234567'],
            '201 characters' => [str_repeat('x', 201)],
        ];
    }

    #[DataProvider('validIdempotencyKeyBoundaries')]
    public function test_start_accepts_idempotency_key_boundaries(string $idempotencyKey): void
    {
        $this->withToken('test-token')->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/interviews', [
                'contract_version' => '1.0',
                'site_id' => 'site-key-bounds',
            ])->assertCreated();
        $this->assertDatabaseCount('interview_sessions', 1);
    }

    /** @return array<string,array{0:string}> */
    public static function validIdempotencyKeyBoundaries(): array
    {
        return [
            'eight characters' => ['12345678'],
            '200 characters' => [str_repeat('x', 200)],
        ];
    }

    public function test_answer_returns_422_when_expected_step_is_missing(): void
    {
        $sessionId = $this->startInterview('site-answer-validation', 'start-validation')->json('id');

        $this->withToken('test-token')->withHeader('Idempotency-Key', 'answer-validation')
            ->postJson("/api/v1/interviews/{$sessionId}/messages", [
                'contract_version' => '1.0',
                'answer' => 'Web工房',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('expected_step');
        $session = InterviewSession::query()->findOrFail($sessionId);
        $this->assertSame(0, $session->current_step);
    }

    public function test_answer_returns_422_when_idempotency_key_is_missing(): void
    {
        $sessionId = $this->startInterview('site-answer-key-validation', 'start-key-validation')->json('id');

        $this->withToken('test-token')->withoutHeader('Idempotency-Key')->postJson("/api/v1/interviews/{$sessionId}/messages", [
            'contract_version' => '1.0',
            'expected_step' => 0,
            'answer' => 'Web工房',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('Idempotency-Key');
        $session = InterviewSession::query()->findOrFail($sessionId);
        $this->assertSame(0, $session->current_step);
    }

    private function startInterview(string $siteId, string $idempotencyKey): TestResponse
    {
        return $this->withToken('test-token')->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/interviews', [
                'contract_version' => '1.0',
                'site_id' => $siteId,
                'locale' => 'ja',
            ])->assertCreated();
    }

    private function temporaryContractsSnapshot(): string
    {
        $directory = storage_path('framework/testing/contracts-'.str()->uuid());
        File::copyDirectory((string) config('interview.contracts_root'), $directory);

        return $directory;
    }
}
