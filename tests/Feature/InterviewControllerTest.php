<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
        $start = $this->withToken('test-token')->postJson('/api/v1/interviews', [
            'contract_version' => '1.0',
            'site_id' => 'site-one',
            'locale' => 'ja',
        ])->assertCreated()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('next_question.field', 'organization');

        $id = $start->json('id');
        foreach (['Web工房', '問い合わせ増加', '中小企業', '誠実で明快', '5ページ', "https://example.com\n資料なし"] as $index => $answer) {
            $response = $this->withToken('test-token')->postJson("/api/v1/interviews/{$id}/messages", [
                'contract_version' => '1.0',
                'answer' => $answer,
            ])->assertOk();
            if ($index < 5) {
                $response->assertJsonPath('status', 'active');
            }
        }

        $response->assertJsonPath('status', 'completed')
            ->assertJsonPath('structured_data.organization', 'Web工房')
            ->assertJsonPath('structured_data.materials.0', 'https://example.com');
        $this->withToken('test-token')->postJson("/api/v1/interviews/{$id}/messages", [
            'contract_version' => '1.0',
            'answer' => 'extra',
        ])->assertConflict();
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
    }

    public function test_authentication_and_session_deletion_are_enforced(): void
    {
        $this->postJson('/api/v1/interviews', [
            'contract_version' => '1.0',
            'site_id' => 'site-one',
        ])->assertUnauthorized();

        $start = $this->withToken('test-token')->postJson('/api/v1/interviews', [
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
            ->assertJsonPath('checks.interview_engine', true);

        $this->get('/interviews')->assertNotFound();
    }
}
