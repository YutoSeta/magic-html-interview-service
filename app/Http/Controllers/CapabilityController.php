<?php

namespace App\Http\Controllers;

use App\Http\Resources\CapabilityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Yutoseta\InterviewEngine\Services\StructuredInterviewStateEngine;

final class CapabilityController extends Controller
{
    /** @var array<string,string> */
    private const array INTERVIEW_SCHEMAS = [
        'answer-request.json' => 'https://contracts.magic-html.dev/v1/interview/answer-request.json',
        'import-request.json' => 'https://contracts.magic-html.dev/v1/interview/import-request.json',
        'intake-session-request.json' => 'https://contracts.magic-html.dev/v1/interview/intake-session-request.json',
        'intake-session-result.json' => 'https://contracts.magic-html.dev/v1/interview/intake-session-result.json',
        'intake-session-status.json' => 'https://contracts.magic-html.dev/v1/interview/intake-session-status.json',
        'intake-template-request.json' => 'https://contracts.magic-html.dev/v1/interview/intake-template-request.json',
        'intake-template-result.json' => 'https://contracts.magic-html.dev/v1/interview/intake-template-result.json',
        'message.json' => 'https://contracts.magic-html.dev/v1/interview/message.json',
        'problem.json' => 'https://contracts.magic-html.dev/v1/interview/problem.json',
        'session.json' => 'https://contracts.magic-html.dev/v1/interview/session.json',
        'start-request.json' => 'https://contracts.magic-html.dev/v1/interview/start-request.json',
        'structured-data.json' => 'https://contracts.magic-html.dev/v1/interview/structured-data.json',
    ];

    /** @var list<array{0:string,1:string,2:string}> */
    private const array INTERVIEW_OPERATIONS = [
        ['/v1/interviews', 'post', 'startInterview'],
        ['/v1/interviews/import', 'post', 'importInterview'],
        ['/v1/interviews/{interview}', 'get', 'getInterview'],
        ['/v1/interviews/{interview}', 'delete', 'deleteInterview'],
        ['/v1/interviews/{interview}/messages', 'post', 'answerInterview'],
        ['/v1/intake-templates/{template}', 'put', 'putGenericIntakeTemplate'],
        ['/v1/intake-templates/{template}/sessions', 'post', 'createGenericIntakeSession'],
        ['/v1/intake-sessions/{interview}', 'get', 'getGenericIntakeSession'],
    ];

    /** @var list<string> */
    private const array REPLAY_SAFE_WRITE_PATHS = [
        '/v1/interviews',
        '/v1/interviews/{interview}/messages',
    ];

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): JsonResource
    {
        return new CapabilityResource([]);
    }

    public function verify(): JsonResponse
    {
        $contractSchemas = $this->interviewSchemasAreReady();
        $contractOperations = $this->interviewOperationsAreReady();
        $checks = [
            'contract_installed' => $contractSchemas && $contractOperations,
            'contract_schemas' => $contractSchemas,
            'contract_operations' => $contractOperations,
            'interview_engine' => class_exists(StructuredInterviewStateEngine::class),
            'database' => Schema::hasTable('interview_sessions')
                && Schema::hasTable('interview_idempotency_records')
                && Schema::hasTable('interview_templates')
                && Schema::hasTable('interviews'),
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

    private function interviewSchemasAreReady(): bool
    {
        $schemasDirectory = $this->contractsRoot().'/schemas/v1/interview';

        foreach (self::INTERVIEW_SCHEMAS as $schema => $expectedId) {
            $document = $this->jsonDocument($schemasDirectory.'/'.$schema);
            if (($document['$id'] ?? null) !== $expectedId) {
                return false;
            }
        }

        $answerRequest = $this->jsonDocument($schemasDirectory.'/answer-request.json');

        if (($answerRequest['required'] ?? null) !== ['contract_version', 'expected_step', 'answer']
            || ($answerRequest['properties']['expected_step']['minimum'] ?? null) !== 0
            || ($answerRequest['properties']['expected_step']['maximum'] ?? null) !== 6) {
            return false;
        }

        $intakeTemplate = $this->jsonDocument($schemasDirectory.'/intake-template-request.json');
        $intakeStatus = $this->jsonDocument($schemasDirectory.'/intake-session-status.json');

        return ($intakeTemplate['additionalProperties'] ?? null) === false
            && ($intakeTemplate['properties']['fields']['items']['$ref'] ?? null) === '../template/field.json'
            && ($intakeTemplate['properties']['fields']['minItems'] ?? null) === 1
            && ($intakeTemplate['properties']['fields']['maxItems'] ?? null) === 200
            && ($intakeStatus['properties']['status']['enum'] ?? null) === [
                'in_progress',
                'ready_for_confirmation',
                'confirmed',
                'closing',
                'ended',
                'abandoned',
                'failed',
            ];
    }

    private function interviewOperationsAreReady(): bool
    {
        $openApiPath = $this->contractsRoot().'/openapi/tier1.json';
        $openApi = $this->jsonDocument($openApiPath);
        if ($openApi === null) {
            return false;
        }
        if (version_compare((string) ($openApi['info']['version'] ?? '0.0.0'), '1.16.0', '<')) {
            return false;
        }

        foreach (self::INTERVIEW_OPERATIONS as [$path, $method, $operationId]) {
            if (($openApi['paths'][$path][$method]['operationId'] ?? null) !== $operationId) {
                return false;
            }
        }

        $idempotencyParameter = $openApi['components']['parameters']['InterviewWriteIdempotencyKey'] ?? [];
        if (($idempotencyParameter['required'] ?? null) !== true
            || ($idempotencyParameter['schema']['minLength'] ?? null) !== 8
            || ($idempotencyParameter['schema']['maxLength'] ?? null) !== 200) {
            return false;
        }
        foreach (self::REPLAY_SAFE_WRITE_PATHS as $path) {
            $operation = $openApi['paths'][$path]['post'] ?? [];
            if (! in_array(['$ref' => '#/components/parameters/InterviewWriteIdempotencyKey'], $operation['parameters'] ?? [], true)
                || ! isset($operation['responses']['409'])) {
                return false;
            }
        }

        $intakeSessionParameters = $openApi['paths']['/v1/intake-templates/{template}/sessions']['post']['parameters'] ?? [];
        $intakeIdempotency = collect($intakeSessionParameters)->first(
            static fn (mixed $parameter): bool => is_array($parameter)
                && ($parameter['name'] ?? null) === 'Idempotency-Key'
                && ($parameter['in'] ?? null) === 'header',
        );
        if (! is_array($intakeIdempotency)
            || ($intakeIdempotency['required'] ?? null) !== true
            || ($intakeIdempotency['schema']['minLength'] ?? null) !== 8
            || ($intakeIdempotency['schema']['maxLength'] ?? null) !== 200) {
            return false;
        }

        return true;
    }

    private function contractsRoot(): string
    {
        return rtrim((string) config('interview.contracts_root'), '/');
    }

    /** @return array<string,mixed>|null */
    private function jsonDocument(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            return null;
        }

        try {
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($document) ? $document : null;
    }
}
