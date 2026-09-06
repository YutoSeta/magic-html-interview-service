<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Yutoseta\InterviewEngine\Models\Interview;

final class IntakeControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://interview.example.test');
        config()->set('interview.service_token', 'test-token');
        config()->set('interview.intake_share_ttl_minutes', 10);
    }

    public function test_service_authentication_is_required_for_every_intake_api(): void
    {
        $this->putJson('/api/v1/intake-templates/contract-v1', $this->definitionPayload())
            ->assertUnauthorized();
        $this->postJson('/api/v1/intake-templates/contract-v1/sessions', [
            'contract_version' => '1.0',
        ])->assertUnauthorized();
        $this->getJson('/api/v1/intake-sessions/00000000-0000-4000-8000-000000000000')
            ->assertUnauthorized();

        $this->assertDatabaseEmpty('interview_templates');
        $this->assertDatabaseEmpty('interviews');
    }

    public function test_the_landing_page_hearing_from_the_contracts_registers_as_an_intake_definition(): void
    {
        // Neo Styler's landing-page hearing is not fixed in this service: it is a
        // Generic Intake template an orchestrator registers. The contracts ship
        // it as a fixture; it must be accepted as it is.
        $payload = json_decode((string) file_get_contents(base_path('packages/magic-html-contracts/tests/fixtures/neo-styler/landing-intake-template.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->withToken('test-token')
            ->putJson('/api/v1/intake-templates/landing-page', $payload)
            ->assertCreated()
            ->assertJsonPath('template', 'landing-page')
            ->assertJsonPath('mode', 'chat')
            ->assertJsonCount(count($payload['fields']), 'fields');

        $paths = array_column($payload['fields'], 'path');
        $this->assertContains('site_name', $paths);
        $this->assertContains('cv_goal', $paths);
        $this->assertContains('links', $paths);
        $this->assertContains('business_name', $paths);
    }

    public function test_immutable_intake_definition_is_created_replayed_and_conflicted(): void
    {
        $payload = $this->definitionPayload();

        $this->withToken('test-token')
            ->putJson('/api/v1/intake-templates/contract-v1', $payload)
            ->assertCreated()
            ->assertExactJson([
                'contract_version' => '1.0',
                'template' => 'contract-v1',
                'title' => '契約書入力',
                'fields' => $payload['fields'],
                'mode' => 'chat',
            ]);
        $this->withToken('test-token')
            ->putJson('/api/v1/intake-templates/contract-v1', $payload)
            ->assertOk()
            ->assertJsonPath('template', 'contract-v1');

        $payload['title'] = '別の契約書入力';
        $this->withToken('test-token')
            ->putJson('/api/v1/intake-templates/contract-v1', $payload)
            ->assertConflict()
            ->assertJsonPath('type', 'intake_template_conflict');

        $this->assertDatabaseCount('interview_templates', 1);
        $this->assertDatabaseHas('interview_templates', [
            'slug' => 'contract-v1',
            'name' => '契約書入力',
            'is_active' => true,
        ]);
    }

    public function test_returns_422_for_unknown_top_level_definition_property(): void
    {
        $payload = $this->definitionPayload();
        $payload['template_version'] = 'must-not-be-accepted';

        $this->withToken('test-token')
            ->putJson('/api/v1/intake-templates/contract-v1', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'validation_failed')
            ->assertJsonValidationErrors('template_version');

        $this->assertDatabaseEmpty('interview_templates');
    }

    /** @param list<array<string,mixed>> $fields */
    #[DataProvider('invalidFieldDefinitions')]
    public function test_returns_422_for_invalid_or_open_field_definition(array $fields, string $errorKey): void
    {
        $payload = $this->definitionPayload();
        $payload['fields'] = $fields;

        $this->withToken('test-token')
            ->putJson('/api/v1/intake-templates/contract-v1', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'validation_failed')
            ->assertJsonValidationErrors($errorKey);

        $this->assertDatabaseEmpty('interview_templates');
    }

    /** @return array<string,array{0:list<array<string,mixed>>,1:string}> */
    public static function invalidFieldDefinitions(): array
    {
        $field = self::field();

        return [
            'duplicate path' => [[$field, $field], 'fields.1.path'],
            'unknown nested property' => [[$field + ['key' => 'legacy-key']], 'fields.0.key'],
            'non scalar option' => [[array_replace($field, ['options' => [['unsafe']]])], 'fields.0.options.0'],
            'money on integer' => [[array_replace($field, ['type' => 'integer', 'format' => 'money'])], 'fields.0.format'],
            'separator on scalar' => [[$field + ['separator' => '、']], 'fields.0.separator'],
        ];
    }

    public function test_session_creation_replays_same_response_and_rejects_changed_mode(): void
    {
        $this->putDefinition('contract-v1');
        $payload = ['contract_version' => '1.0', 'mode' => 'chat'];

        $first = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'intake-session-0001')
            ->postJson('/api/v1/intake-templates/contract-v1/sessions', $payload)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('contract_version', '1.0')
            ->assertJsonPath('template', 'contract-v1');
        $retry = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'intake-session-0001')
            ->postJson('/api/v1/intake-templates/contract-v1/sessions', $payload)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $retry->json());
        $payload['mode'] = 'form';
        $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'intake-session-0001')
            ->postJson('/api/v1/intake-templates/contract-v1/sessions', $payload)
            ->assertConflict()
            ->assertJsonPath('type', 'idempotency_conflict');
        $this->assertDatabaseCount('interviews', 1);
        $this->assertDatabaseCount('interview_idempotency_records', 1);
    }

    public function test_same_idempotency_key_is_scoped_by_intake_template(): void
    {
        $this->putDefinition('contract-one');
        $this->putDefinition('contract-two');

        $first = $this->createIntakeSession('contract-one', 'shared-intake-key');
        $second = $this->createIntakeSession('contract-two', 'shared-intake-key');

        $this->assertNotSame($first->json('uuid'), $second->json('uuid'));
        $this->assertDatabaseCount('interviews', 2);
        $this->assertDatabaseCount('interview_idempotency_records', 2);
    }

    public function test_signed_entry_grants_only_its_uuid_to_the_browser_session(): void
    {
        $this->putDefinition('contract-one');
        $this->putDefinition('contract-two');
        $first = $this->createIntakeSession('contract-one', 'signed-session-one');
        $second = $this->createIntakeSession('contract-two', 'signed-session-two');
        $firstUuid = (string) $first->json('uuid');
        $secondUuid = (string) $second->json('uuid');
        $expiresAt = $this->expiresTimestamp((string) $first->json('url'));

        foreach ($this->protectedBrowserRoutes($firstUuid) as [$method, $uri]) {
            $this->json($method, $uri)->assertForbidden();
        }
        $tamperedUrl = str_replace($firstUuid, $secondUuid, (string) $first->json('url'));
        $this->get($tamperedUrl)->assertForbidden();
        $this->get((string) $first->json('url'))
            ->assertOk()
            ->assertSessionHas("intake_access.{$firstUuid}", $expiresAt);

        $this->withSession(["intake_access.{$firstUuid}" => $expiresAt])
            ->getJson("/i/{$firstUuid}/progress")
            ->assertOk();
        foreach ($this->protectedBrowserRoutes($secondUuid) as [$method, $uri]) {
            $this->withSession(["intake_access.{$firstUuid}" => $expiresAt])
                ->json($method, $uri)
                ->assertForbidden();
        }
    }

    public function test_signed_entry_and_session_grant_expire(): void
    {
        $this->travelTo('2026-08-27 12:00:00');
        $this->putDefinition('expiring-contract');
        $session = $this->createIntakeSession('expiring-contract', 'expiring-session');
        $uuid = (string) $session->json('uuid');
        $url = (string) $session->json('url');
        $expiresAt = $this->expiresTimestamp($url);

        $this->get($url)->assertOk();
        $this->travel(11)->minutes();

        $this->get($url)->assertForbidden();
        $this->withSession(["intake_access.{$uuid}" => $expiresAt])
            ->getJson("/i/{$uuid}/progress")
            ->assertForbidden();
    }

    #[DataProvider('booleanBrowserAnswers')]
    public function test_browser_ui_boolean_answer_is_normalized_and_can_be_confirmed(
        string $mode,
        string $browserValue,
        bool $expected,
    ): void {
        $field = [
            'path' => 'terms.accepted',
            'label' => '規約への同意',
            'question' => '規約に同意しますか？',
            'type' => 'boolean',
            'required' => true,
        ];
        $this->putDefinition('boolean-contract-'.$mode, [$field], $mode);
        $session = $this->createIntakeSession('boolean-contract-'.$mode, 'boolean-session-'.$mode, $mode);
        $uuid = (string) $session->json('uuid');
        $expiresAt = $this->expiresTimestamp((string) $session->json('url'));

        $this->get((string) $session->json('url'))
            ->assertOk()
            ->assertSee('はい')
            ->assertSee('いいえ');
        $answer = $this->withSession(["intake_access.{$uuid}" => $expiresAt])
            ->postJson("/i/{$uuid}/answer", [
                'answers' => [['path' => 'terms.accepted', 'value' => $browserValue]],
            ])->assertOk()
            ->assertJsonPath('next_action', 'confirm_summary');
        $this->assertSame($expected, $answer->json('values')['terms.accepted']['value']);
        $this->withSession(["intake_access.{$uuid}" => $expiresAt])
            ->postJson("/i/{$uuid}/confirm")
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');
        $this->withSession(["intake_access.{$uuid}" => $expiresAt])
            ->postJson("/i/{$uuid}/closing")
            ->assertOk()
            ->assertJsonPath('status', 'closing');
        $this->withSession(["intake_access.{$uuid}" => $expiresAt])
            ->postJson("/i/{$uuid}/ended")
            ->assertOk()
            ->assertJsonPath('status', 'ended');

        $status = $this->withToken('test-token')
            ->getJson("/api/v1/intake-sessions/{$uuid}")
            ->assertOk()
            ->assertJsonPath('status', 'ended');
        $this->assertSame($expected, $status->json('values')['terms.accepted']);
    }

    /** @return array<string,array{0:string,1:string,2:bool}> */
    public static function booleanBrowserAnswers(): array
    {
        return [
            'chat yes' => ['chat', 'はい', true],
            'form no' => ['form', 'いいえ', false],
        ];
    }

    public function test_unknown_browser_answer_path_is_rejected_without_state_change(): void
    {
        $this->putDefinition('known-fields');
        $session = $this->createIntakeSession('known-fields', 'unknown-answer-path');
        $uuid = (string) $session->json('uuid');
        $expiresAt = $this->expiresTimestamp((string) $session->json('url'));

        $this->withSession(["intake_access.{$uuid}" => $expiresAt])
            ->postJson("/i/{$uuid}/answer", [
                'answers' => [['path' => 'unknown.path', 'value' => 'unsafe']],
            ])->assertUnprocessable();

        $interview = Interview::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame([], (array) data_get($interview->live_state, 'values', []));
    }

    public function test_capability_readiness_includes_generic_intake_contracts_and_routes(): void
    {
        $this->assertFalse(Route::has('interviews.store'));

        $this->getJson('/api/__verify')
            ->assertOk()
            ->assertJsonPath('checks.contract_installed', true)
            ->assertJsonPath('checks.contract_schemas', true)
            ->assertJsonPath('checks.contract_operations', true)
            ->assertJsonPath('checks.interview_engine', true)
            ->assertJsonPath('checks.database', true);

        $this->getJson('/api')
            ->assertOk()
            ->assertJsonFragment(['PUT /api/v1/intake-templates/{template}'])
            ->assertJsonFragment(['POST /api/v1/intake-templates/{template}/sessions'])
            ->assertJsonFragment(['GET /api/v1/intake-sessions/{interview}'])
            ->assertJsonPath('write_safety.intake_browser_access.entry', 'temporary_signed_url');
    }

    /** @param list<array<string,mixed>>|null $fields */
    private function putDefinition(string $key, ?array $fields = null, string $mode = 'chat'): TestResponse
    {
        return $this->withToken('test-token')
            ->putJson("/api/v1/intake-templates/{$key}", $this->definitionPayload($fields, $mode))
            ->assertCreated();
    }

    private function createIntakeSession(string $key, string $idempotencyKey, string $mode = 'chat'): TestResponse
    {
        return $this->withToken('test-token')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/intake-templates/{$key}/sessions", [
                'contract_version' => '1.0',
                'mode' => $mode,
            ])
            ->assertCreated();
    }

    /**
     * @param  list<array<string,mixed>>|null  $fields
     * @return array<string,mixed>
     */
    private function definitionPayload(?array $fields = null, string $mode = 'chat'): array
    {
        return [
            'contract_version' => '1.0',
            'title' => '契約書入力',
            'description' => '契約書作成に必要な情報を収集します。',
            'fields' => $fields ?? [self::field()],
            'mode' => $mode,
            'greeting' => '必要事項を順番に伺います。',
            'closing_script' => 'ご回答ありがとうございました。',
        ];
    }

    /** @return array<string,mixed> */
    private static function field(): array
    {
        return [
            'path' => 'client.company_name',
            'label' => '会社名',
            'question' => '正式な会社名を教えてください。',
            'type' => 'string',
            'required' => true,
        ];
    }

    private function expiresTimestamp(string $url): int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (int) ($query['expires'] ?? 0);
    }

    /** @return list<array{0:string,1:string}> */
    private function protectedBrowserRoutes(string $uuid): array
    {
        return [
            ['GET', "/i/{$uuid}/progress"],
            ['POST', "/i/{$uuid}/answer"],
            ['POST', "/i/{$uuid}/confirm"],
            ['POST', "/i/{$uuid}/closing"],
            ['POST', "/i/{$uuid}/ended"],
        ];
    }
}
