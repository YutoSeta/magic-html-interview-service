<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$jsonFiles = [];

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'json' && ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR)) {
        $jsonFiles[] = $file->getPathname();
    }
}

sort($jsonFiles);
$errors = [];

foreach (['tier0', 'tier1', 'tier2', 'tier3'] as $tierName) {
    try {
        $openApi = json_decode((string) file_get_contents($root.'/openapi/'.$tierName.'.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($openApi['servers'] ?? null) !== [['url' => '/api']]) {
            $errors[] = sprintf('OpenAPI %s must declare the common /api server base.', $tierName);
        }
        foreach (array_keys($openApi['paths'] ?? []) as $path) {
            if (str_starts_with((string) $path, '/api/')) {
                $errors[] = sprintf('OpenAPI %s path %s must be relative to the /api server base.', $tierName, $path);
            }
        }
    } catch (JsonException $exception) {
        $errors[] = sprintf('Unable to inspect OpenAPI server base for %s: %s', $tierName, $exception->getMessage());
    }
}

foreach ($jsonFiles as $path) {
    try {
        $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $errors[] = sprintf('%s: invalid JSON: %s', $path, $exception->getMessage());

        continue;
    }

    $walk = function (mixed $value) use (&$walk, &$errors, $path): void {
        if (! is_array($value)) {
            return;
        }

        if (isset($value['$ref']) && is_string($value['$ref']) && ! str_starts_with($value['$ref'], '#') && ! preg_match('~^https?://~', $value['$ref'])) {
            $referencePath = explode('#', $value['$ref'], 2)[0];
            $target = realpath(dirname($path).DIRECTORY_SEPARATOR.$referencePath);
            if ($target === false || ! is_file($target)) {
                $errors[] = sprintf('%s: missing local $ref %s', $path, $value['$ref']);
            }
        }

        foreach ($value as $child) {
            $walk($child);
        }
    };

    $walk($document);
}

try {
    $manifestSchema = json_decode((string) file_get_contents($root.'/schemas/v1/static-build/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $expectedDigestDescription = 'SHA-256 lowercase hex of the bytes formed by sorting files by path in bytewise ascending order and concatenating, for each file, path + NUL + lowercase sha256 + NUL.';
    if (($manifestSchema['properties']['digest']['description'] ?? null) !== $expectedDigestDescription) {
        $errors[] = 'Static Builder manifest digest must document the canonical bytewise algorithm.';
    }

    $digestFixture = [
        ['path' => 'index.html', 'sha256' => str_repeat('b', 64)],
        ['path' => 'assets/app.css', 'sha256' => str_repeat('a', 64)],
        ['path' => 'assets/Z.js', 'sha256' => str_repeat('0', 64)],
    ];
    usort($digestFixture, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
    $digestBytes = '';
    foreach ($digestFixture as $file) {
        $digestBytes .= $file['path']."\0".$file['sha256']."\0";
    }
    if (hash('sha256', $digestBytes) !== '3a2a37894bb540f5ba9d1fb093724a2a201d8410097efe6001f6643a38f8cb7e') {
        $errors[] = 'Static Builder manifest canonical digest fixture does not match the Builder and Deploy algorithm.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Static Builder manifest digest schema: %s', $exception->getMessage());
}

$approvalDocuments = [
    'schemas/v1/approval/actor.json',
    'schemas/v1/approval/artifact-input.json',
    'schemas/v1/approval/artifact.json',
    'schemas/v1/approval/create-request.json',
    'schemas/v1/approval/decision-request.json',
    'schemas/v1/approval/event.json',
    'schemas/v1/approval/history.json',
    'schemas/v1/approval/list.json',
    'schemas/v1/approval/message-request.json',
    'schemas/v1/approval/problem.json',
    'schemas/v1/approval/request.json',
];

foreach ($approvalDocuments as $relativePath) {
    if (! in_array($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath), $jsonFiles, true)) {
        $errors[] = sprintf('Missing approval contract document %s', $relativePath);
    }
}

try {
    $tier1 = json_decode((string) file_get_contents($root.'/openapi/tier1.json'), true, flags: JSON_THROW_ON_ERROR);
    $approvalPaths = [
        '/v1/tenants/{tenant}/sites/{site}/approval-requests',
        '/v1/tenants/{tenant}/sites/{site}/approval-requests/{approval}',
        '/v1/tenants/{tenant}/sites/{site}/approval-requests/{approval}/messages',
        '/v1/tenants/{tenant}/sites/{site}/approval-requests/{approval}/decisions',
        '/v1/tenants/{tenant}/sites/{site}/approval-requests/{approval}/history',
    ];

    foreach ($approvalPaths as $approvalPath) {
        if (! isset($tier1['paths'][$approvalPath])) {
            $errors[] = sprintf('Tier 1 OpenAPI is missing approval path %s', $approvalPath);
        }
    }

    if (($tier1['components']['securitySchemes']['serviceBearer']['scheme'] ?? null) !== 'bearer') {
        $errors[] = 'Tier 1 Approval operations must declare trusted-service Bearer authentication.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Tier 1 approval inventory: %s', $exception->getMessage());
}

$siteEditDocuments = [
    'schemas/v1/site-edit/inspect-request.json',
    'schemas/v1/site-edit/inspect-result.json',
    'schemas/v1/site-edit/request.json',
    'schemas/v1/site-edit/result.json',
];

foreach ($siteEditDocuments as $relativePath) {
    if (! in_array($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath), $jsonFiles, true)) {
        $errors[] = sprintf('Missing Site Edit contract document %s', $relativePath);
    }
}

try {
    $inspectRequest = json_decode((string) file_get_contents($root.'/schemas/v1/site-edit/inspect-request.json'), true, flags: JSON_THROW_ON_ERROR);
    $expectedRequestProperties = ['contract_version', 'html', 'marker_locked', 'page_path', 'site_id'];
    $requestProperties = array_keys($inspectRequest['properties'] ?? []);
    sort($requestProperties);
    if (($inspectRequest['additionalProperties'] ?? null) !== false || $requestProperties !== $expectedRequestProperties) {
        $errors[] = 'Site Edit inspection requests must be closed and expose only the defined inspection fields.';
    }

    $requestRequired = $inspectRequest['required'] ?? [];
    sort($requestRequired);
    if ($requestRequired !== ['contract_version', 'html', 'page_path', 'site_id']) {
        $errors[] = 'Site Edit inspection must require contract_version, site_id, page_path, and html only.';
    }
    if (($inspectRequest['properties']['marker_locked']['default'] ?? null) !== false) {
        $errors[] = 'Site Edit inspection marker_locked must default to false.';
    }
    if (($inspectRequest['properties']['html']['minLength'] ?? null) !== 1 || ($inspectRequest['properties']['html']['maxLength'] ?? null) !== 2000000) {
        $errors[] = 'Site Edit inspection HTML must be bounded from 1 to 2,000,000 characters.';
    }

    $editRequest = json_decode((string) file_get_contents($root.'/schemas/v1/site-edit/request.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach (['site_id', 'page_path'] as $sharedProperty) {
        if (($inspectRequest['properties'][$sharedProperty] ?? null) !== ($editRequest['properties'][$sharedProperty] ?? null)) {
            $errors[] = sprintf('Site Edit inspection and application must share the %s constraint.', $sharedProperty);
        }
    }

    $inspectResult = json_decode((string) file_get_contents($root.'/schemas/v1/site-edit/inspect-result.json'), true, flags: JSON_THROW_ON_ERROR);
    $expectedResultProperties = ['base_digest', 'candidates', 'contract_version', 'page_path', 'site_id', 'truncated'];
    $resultProperties = array_keys($inspectResult['properties'] ?? []);
    sort($resultProperties);
    $resultRequired = $inspectResult['required'] ?? [];
    sort($resultRequired);
    if (($inspectResult['additionalProperties'] ?? null) !== false || $resultProperties !== $expectedResultProperties || $resultRequired !== $expectedResultProperties) {
        $errors[] = 'Site Edit inspection results must be closed and require every defined result field.';
    }
    if (($inspectResult['properties']['base_digest']['pattern'] ?? null) !== '^[a-f0-9]{64}$') {
        $errors[] = 'Site Edit inspection base_digest must be a lowercase SHA-256 digest.';
    }
    if (($inspectResult['properties']['candidates']['maxItems'] ?? null) !== 200) {
        $errors[] = 'Site Edit inspection must return at most 200 candidates.';
    }

    $candidate = $inspectResult['properties']['candidates']['items'] ?? [];
    $expectedCandidateProperties = ['alt', 'id', 'marker_kind', 'operation', 'parent_tag', 'section_heading', 'type', 'value', 'xpath'];
    $candidateProperties = array_keys($candidate['properties'] ?? []);
    sort($candidateProperties);
    $candidateRequired = $candidate['required'] ?? [];
    sort($candidateRequired);
    if (($candidate['additionalProperties'] ?? null) !== false || $candidateProperties !== $expectedCandidateProperties || $candidateRequired !== $expectedCandidateProperties) {
        $errors[] = 'Site Edit inspection candidates must be closed and require every defined candidate field.';
    }

    $candidateLimits = [
        'value' => 10000,
        'alt' => 1000,
        'section_heading' => 1000,
        'parent_tag' => 32,
        'marker_kind' => 64,
        'xpath' => 2000,
    ];
    foreach ($candidateLimits as $property => $maximum) {
        if (($candidate['properties'][$property]['maxLength'] ?? null) !== $maximum) {
            $errors[] = sprintf('Site Edit candidate %s must have maxLength %d.', $property, $maximum);
        }
    }
    foreach (['alt', 'section_heading', 'parent_tag', 'marker_kind'] as $nullableProperty) {
        if (($candidate['properties'][$nullableProperty]['type'] ?? null) !== ['string', 'null']) {
            $errors[] = sprintf('Site Edit candidate %s must be an explicit nullable string.', $nullableProperty);
        }
    }

    $expectedMappings = ['image' => 'replace_image', 'link' => 'replace_href', 'text' => 'replace_text'];
    $actualMappings = [];
    foreach ($candidate['oneOf'] ?? [] as $mapping) {
        $type = $mapping['properties']['type']['const'] ?? null;
        $operation = $mapping['properties']['operation']['const'] ?? null;
        if (is_string($type) && is_string($operation)) {
            $actualMappings[$type] = $operation;
        }
    }
    ksort($actualMappings);
    if ($actualMappings !== $expectedMappings) {
        $errors[] = 'Site Edit candidate types must map exactly to their finite edit operations.';
    }

    $candidateIdPattern = $candidate['properties']['id']['pattern'] ?? '';
    foreach (['c1', 'c9', 'c10', 'c99', 'c100', 'c199', 'c200'] as $acceptedId) {
        if (! is_string($candidateIdPattern) || preg_match('~'.$candidateIdPattern.'~D', $acceptedId) !== 1) {
            $errors[] = sprintf('Site Edit candidate IDs must accept bounded fixture %s.', $acceptedId);
        }
    }
    foreach (['c0', 'c01', 'c201', 'c999', 'candidate1'] as $rejectedId) {
        if (is_string($candidateIdPattern) && preg_match('~'.$candidateIdPattern.'~D', $rejectedId) === 1) {
            $errors[] = sprintf('Site Edit candidate IDs must reject out-of-range fixture %s.', $rejectedId);
        }
    }

    $applicationXpath = $editRequest['properties']['operations']['items']['properties']['xpath'] ?? null;
    if (($candidate['properties']['xpath'] ?? null) !== $applicationXpath) {
        $errors[] = 'Site Edit inspection must emit the same canonical XPath accepted by edit application.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Site Edit schemas: %s', $exception->getMessage());
}

try {
    $tier1 = json_decode((string) file_get_contents($root.'/openapi/tier1.json'), true, flags: JSON_THROW_ON_ERROR);
    $inspection = $tier1['paths']['/v1/site-edit-targets']['post'] ?? [];
    if ($inspection === []) {
        $errors[] = 'Tier 1 OpenAPI is missing POST /v1/site-edit-targets.';
    }
    if (($inspection['security'][0]['serviceBearer'] ?? null) !== []) {
        $errors[] = 'Site Edit target inspection must require service Bearer authentication.';
    }
    if (($inspection['requestBody']['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/site-edit/inspect-request.json') {
        $errors[] = 'Site Edit target inspection must use the closed inspection request schema.';
    }
    if (($inspection['responses']['200']['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/site-edit/inspect-result.json') {
        $errors[] = 'Site Edit target inspection must expose the bounded inspection result schema.';
    }
    if (isset($inspection['parameters'])) {
        $errors[] = 'Synchronous side-effect-free Site Edit target inspection must not require an Idempotency-Key.';
    }
    if (($tier1['info']['version'] ?? null) !== '1.16.0') {
        $errors[] = 'Tier 1 OpenAPI version must include deterministic semantic templates and generic Intake in addition to the existing Tier 1 inventories.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Tier 1 Site Edit target inventory: %s', $exception->getMessage());
}

$templateDocuments = [
    'schemas/v1/template/bind-request.json',
    'schemas/v1/template/bind-result.json',
    'schemas/v1/template/field.json',
    'schemas/v1/template/inspect-request.json',
    'schemas/v1/template/inspect-result.json',
    'schemas/v1/template/manifest.json',
    'schemas/v1/template/markdown-request.json',
    'schemas/v1/template/markdown-result.json',
    'schemas/v1/template/template-result.json',
    'schemas/v1/template/upsert-request.json',
];

$intakeDocuments = [
    'schemas/v1/interview/intake-session-request.json',
    'schemas/v1/interview/intake-session-result.json',
    'schemas/v1/interview/intake-session-status.json',
    'schemas/v1/interview/intake-template-request.json',
    'schemas/v1/interview/intake-template-result.json',
];

foreach ([...$templateDocuments, ...$intakeDocuments] as $relativePath) {
    if (! in_array($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath), $jsonFiles, true)) {
        $errors[] = sprintf('Missing Template or generic Intake contract document %s', $relativePath);
    }
}

try {
    $field = json_decode((string) file_get_contents($root.'/schemas/v1/template/field.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifest = json_decode((string) file_get_contents($root.'/schemas/v1/template/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $templateResult = json_decode((string) file_get_contents($root.'/schemas/v1/template/template-result.json'), true, flags: JSON_THROW_ON_ERROR);
    $bindRequest = json_decode((string) file_get_contents($root.'/schemas/v1/template/bind-request.json'), true, flags: JSON_THROW_ON_ERROR);
    $bindResult = json_decode((string) file_get_contents($root.'/schemas/v1/template/bind-result.json'), true, flags: JSON_THROW_ON_ERROR);
    $markdownResult = json_decode((string) file_get_contents($root.'/schemas/v1/template/markdown-result.json'), true, flags: JSON_THROW_ON_ERROR);
    $intakeTemplateRequest = json_decode((string) file_get_contents($root.'/schemas/v1/interview/intake-template-request.json'), true, flags: JSON_THROW_ON_ERROR);
    $intakeSessionStatus = json_decode((string) file_get_contents($root.'/schemas/v1/interview/intake-session-status.json'), true, flags: JSON_THROW_ON_ERROR);

    if (($field['additionalProperties'] ?? null) !== false
        || ($field['required'] ?? null) !== ['path', 'label', 'question', 'type', 'required']
        || ($field['properties']['path']['pattern'] ?? null) !== '^[A-Za-z][A-Za-z0-9_-]*(\\.[A-Za-z][A-Za-z0-9_-]*)*$') {
        $errors[] = 'Template fields must be closed and use the canonical bounded dot-path identifier.';
    }
    if (($field['properties']['type']['enum'] ?? null) !== ['string', 'text', 'number', 'integer', 'boolean', 'array', 'email', 'url']
        || ($field['properties']['format']['enum'] ?? null) !== ['date', 'money']) {
        $errors[] = 'Template fields must preserve the canonical normalized types and date/money semantic formats.';
    }
    $expectedFieldFormatVariants = [
        ['properties' => ['type' => ['const' => 'string'], 'format' => ['const' => 'date']], 'required' => ['format']],
        ['properties' => ['type' => ['const' => 'number'], 'format' => ['const' => 'money']], 'required' => ['format']],
        ['not' => ['required' => ['format']]],
    ];
    if (($field['oneOf'] ?? null) !== $expectedFieldFormatVariants) {
        $errors[] = 'Template field format must allow only normalized string/date, number/money, or a type without format.';
    }
    if (($manifest['properties']['fields']['items']['$ref'] ?? null) !== 'field.json'
        || ($manifest['properties']['fields']['maxItems'] ?? null) !== 200
        || ($manifest['properties']['schema']['type'] ?? null) !== 'object') {
        $errors[] = 'Template manifests must expose at most 200 canonical fields and their generated JSON Schema.';
    }
    foreach (['sha256', 'manifest_sha256', 'digest'] as $digestProperty) {
        if (($templateResult['properties'][$digestProperty]['pattern'] ?? null) !== '^[a-f0-9]{64}$') {
            $errors[] = sprintf('Template result %s must be a lowercase SHA-256 digest.', $digestProperty);
        }
    }
    if (($templateResult['properties']['version']['pattern'] ?? null) !== '^v1-[a-f0-9]{20}$'
        || ($bindRequest['properties']['version']['pattern'] ?? null) !== '^v1-[a-f0-9]{20}$') {
        $errors[] = 'Template storage and binding must share the pinned immutable version format.';
    }
    foreach ([$markdownResult, $bindResult] as $documentHandoffResult) {
        if (($documentHandoffResult['properties']['html']['$ref'] ?? null) !== '../document/html.json') {
            $errors[] = sprintf('Template result %s must hand off HTML through the shared isolated Document HTML contract.', $documentHandoffResult['$id'] ?? 'unknown');
        }
    }
    if (($intakeTemplateRequest['additionalProperties'] ?? null) !== false
        || ($intakeTemplateRequest['properties']['fields']['items']['$ref'] ?? null) !== '../template/field.json'
        || ($intakeTemplateRequest['properties']['fields']['minItems'] ?? null) !== 1
        || ($intakeTemplateRequest['properties']['fields']['maxItems'] ?? null) !== 200) {
        $errors[] = 'Generic Intake must consume between 1 and 200 canonical Template fields.';
    }
    if (($intakeSessionStatus['properties']['status']['enum'] ?? null) !== ['in_progress', 'ready_for_confirmation', 'confirmed', 'closing', 'ended', 'abandoned', 'failed']) {
        $errors[] = 'Generic Intake status must preserve the interview-engine lifecycle states.';
    }

    $tier1 = json_decode((string) file_get_contents($root.'/openapi/tier1.json'), true, flags: JSON_THROW_ON_ERROR);
    $operations = [
        ['/v1/template-inspections', 'post', '../schemas/v1/template/inspect-request.json', '200', '../schemas/v1/template/inspect-result.json'],
        ['/v1/markdown-renders', 'post', '../schemas/v1/template/markdown-request.json', '200', '../schemas/v1/template/markdown-result.json'],
        ['/v1/templates/{template}', 'put', '../schemas/v1/template/upsert-request.json', '200', '../schemas/v1/template/template-result.json'],
        ['/v1/templates/{template}', 'get', null, '200', '../schemas/v1/template/template-result.json'],
        ['/v1/templates/{template}/versions/{version}', 'get', null, '200', '../schemas/v1/template/template-result.json'],
        ['/v1/templates/{template}/bindings', 'post', '../schemas/v1/template/bind-request.json', '200', '../schemas/v1/template/bind-result.json'],
        ['/v1/intake-templates/{template}', 'put', '../schemas/v1/interview/intake-template-request.json', '201', '../schemas/v1/interview/intake-template-result.json'],
        ['/v1/intake-templates/{template}/sessions', 'post', '../schemas/v1/interview/intake-session-request.json', '201', '../schemas/v1/interview/intake-session-result.json'],
        ['/v1/intake-sessions/{interview}', 'get', null, '200', '../schemas/v1/interview/intake-session-status.json'],
    ];
    foreach ($operations as [$path, $method, $requestRef, $successStatus, $responseRef]) {
        $operation = $tier1['paths'][$path][$method] ?? [];
        if ($operation === []) {
            $errors[] = sprintf('Tier 1 OpenAPI is missing Template/Intake operation %s %s.', strtoupper($method), $path);

            continue;
        }
        if (($operation['security'][0]['serviceBearer'] ?? null) !== []) {
            $errors[] = sprintf('Template/Intake operation %s %s must require service Bearer authentication.', strtoupper($method), $path);
        }
        if ($requestRef !== null && ($operation['requestBody']['content']['application/json']['schema']['$ref'] ?? null) !== $requestRef) {
            $errors[] = sprintf('Template/Intake operation %s %s must use request schema %s.', strtoupper($method), $path, $requestRef);
        }
        if (($operation['responses'][$successStatus]['content']['application/json']['schema']['$ref'] ?? null) !== $responseRef) {
            $errors[] = sprintf('Template/Intake operation %s %s response %s must use %s.', strtoupper($method), $path, $successStatus, $responseRef);
        }
    }
    $sessionParameters = $tier1['paths']['/v1/intake-templates/{template}/sessions']['post']['parameters'] ?? [];
    $idempotencyKey = array_values(array_filter(
        $sessionParameters,
        static fn (array $parameter): bool => ($parameter['name'] ?? null) === 'Idempotency-Key'
    ))[0] ?? [];
    if (($idempotencyKey['required'] ?? null) !== true
        || ($idempotencyKey['schema']['minLength'] ?? null) !== 8
        || ($idempotencyKey['schema']['maxLength'] ?? null) !== 200) {
        $errors[] = 'Generic Intake session creation must require a bounded Idempotency-Key.';
    }
    $sessionResult = json_decode((string) file_get_contents($root.'/schemas/v1/interview/intake-session-result.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($sessionResult['properties']['url']['format'] ?? null) !== 'uri'
        || ($sessionResult['properties']['expires_at']['format'] ?? null) !== 'date-time') {
        $errors[] = 'Generic Intake session creation must return a temporary URL and explicit expiry.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Template and generic Intake contracts: %s', $exception->getMessage());
}

$interviewDocuments = [
    'schemas/v1/interview/answer-request.json',
    'schemas/v1/interview/import-request.json',
    'schemas/v1/interview/message.json',
    'schemas/v1/interview/problem.json',
    'schemas/v1/interview/session.json',
    'schemas/v1/interview/start-request.json',
    'schemas/v1/interview/structured-data.json',
];

foreach ($interviewDocuments as $relativePath) {
    if (! in_array($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath), $jsonFiles, true)) {
        $errors[] = sprintf('Missing Interview contract document %s', $relativePath);
    }
}

try {
    $startInterview = json_decode((string) file_get_contents($root.'/schemas/v1/interview/start-request.json'), true, flags: JSON_THROW_ON_ERROR);
    $importInterview = json_decode((string) file_get_contents($root.'/schemas/v1/interview/import-request.json'), true, flags: JSON_THROW_ON_ERROR);
    $answerInterview = json_decode((string) file_get_contents($root.'/schemas/v1/interview/answer-request.json'), true, flags: JSON_THROW_ON_ERROR);
    $interviewSession = json_decode((string) file_get_contents($root.'/schemas/v1/interview/session.json'), true, flags: JSON_THROW_ON_ERROR);
    $interviewProblem = json_decode((string) file_get_contents($root.'/schemas/v1/interview/problem.json'), true, flags: JSON_THROW_ON_ERROR);

    foreach ([$startInterview, $importInterview, $answerInterview] as $requestSchema) {
        if (($requestSchema['additionalProperties'] ?? null) !== false) {
            $errors[] = sprintf('Interview request schema %s must reject unknown root keys.', $requestSchema['$id'] ?? 'unknown');
        }
    }
    if (($startInterview['required'] ?? null) !== ['contract_version', 'site_id']) {
        $errors[] = 'Interview start must require only contract_version and site_id; locale remains optional with the service default.';
    }
    if (($startInterview['properties']['locale']['default'] ?? null) !== 'ja') {
        $errors[] = 'Interview start locale must document the service default ja.';
    }
    if (($importInterview['required'] ?? null) !== ['contract_version', 'site_id', 'interview']) {
        $errors[] = 'Interview import must require contract_version, site_id, and interview.';
    }
    if (($importInterview['properties']['interview']['required'] ?? null) !== ['organization', 'goals', 'audience', 'tone']) {
        $errors[] = 'Interview import must require the four fields enforced by the service and keep requirements/materials optional.';
    }
    if (($importInterview['properties']['interview']['properties']['materials']['maxItems'] ?? null) !== 30) {
        $errors[] = 'Interview import materials must be bounded to 30 entries.';
    }
    if (($answerInterview['required'] ?? null) !== ['contract_version', 'expected_step', 'answer']
        || ($answerInterview['properties']['answer']['maxLength'] ?? null) !== 8000
        || ($answerInterview['properties']['expected_step']['minimum'] ?? null) !== 0
        || ($answerInterview['properties']['expected_step']['maximum'] ?? null) !== 6) {
        $errors[] = 'Interview answers must require expected_step 0..6 and the versioned answer string bounded to 8000 characters.';
    }

    $sessionRequired = $interviewSession['required'] ?? [];
    foreach (['contract_version', 'id', 'site_id', 'locale', 'status', 'current_step', 'messages', 'next_question', 'structured_data', 'created_at', 'updated_at'] as $requiredField) {
        if (! in_array($requiredField, $sessionRequired, true)) {
            $errors[] = sprintf('Interview session responses must require %s.', $requiredField);
        }
    }
    if (($interviewSession['properties']['messages']['items']['$ref'] ?? null) !== 'message.json'
        || ($interviewSession['properties']['structured_data']['$ref'] ?? null) !== 'structured-data.json') {
        $errors[] = 'Interview sessions must use the shared message and structured-data schemas.';
    }
    $expectedProblemTypes = ['unauthorized', 'validation_failed', 'idempotency_conflict', 'idempotency_in_progress', 'interview_not_found', 'interview_step_conflict', 'interview_completed'];
    if (($interviewProblem['properties']['type']['enum'] ?? null) !== $expectedProblemTypes) {
        $errors[] = 'Interview Problem types must match the service controller and authentication middleware.';
    }

    $tier1 = json_decode((string) file_get_contents($root.'/openapi/tier1.json'), true, flags: JSON_THROW_ON_ERROR);
    $interviewOperations = [
        ['/v1/interviews', 'post', '../schemas/v1/interview/start-request.json', '201'],
        ['/v1/interviews/import', 'post', '../schemas/v1/interview/import-request.json', '201'],
        ['/v1/interviews/{interview}', 'get', null, '200'],
        ['/v1/interviews/{interview}', 'delete', null, '204'],
        ['/v1/interviews/{interview}/messages', 'post', '../schemas/v1/interview/answer-request.json', '200'],
    ];
    foreach ($interviewOperations as [$path, $method, $requestRef, $successStatus]) {
        $operation = $tier1['paths'][$path][$method] ?? [];
        if ($operation === []) {
            $errors[] = sprintf('Tier 1 OpenAPI is missing Interview operation %s %s.', strtoupper($method), $path);

            continue;
        }
        if (($operation['security'][0]['serviceBearer'] ?? null) !== []) {
            $errors[] = sprintf('Interview operation %s %s must require service Bearer authentication.', strtoupper($method), $path);
        }
        if ($requestRef !== null && ($operation['requestBody']['content']['application/json']['schema']['$ref'] ?? null) !== $requestRef) {
            $errors[] = sprintf('Interview operation %s %s must use request schema %s.', strtoupper($method), $path, $requestRef);
        }
        if (! isset($operation['responses'][$successStatus])) {
            $errors[] = sprintf('Interview operation %s %s must expose success response %s.', strtoupper($method), $path, $successStatus);
        }
    }
    foreach ([
        ['/v1/interviews', 'post', '201'],
        ['/v1/interviews/import', 'post', '200'],
        ['/v1/interviews/import', 'post', '201'],
        ['/v1/interviews/{interview}', 'get', '200'],
        ['/v1/interviews/{interview}/messages', 'post', '200'],
    ] as [$path, $method, $status]) {
        $responseRef = $tier1['paths'][$path][$method]['responses'][$status]['content']['application/json']['schema']['$ref'] ?? null;
        if ($responseRef !== '../schemas/v1/interview/session.json') {
            $errors[] = sprintf('Interview operation %s %s response %s must use the session schema.', strtoupper($method), $path, $status);
        }
    }
    $importParameters = $tier1['paths']['/v1/interviews/import']['post']['parameters'] ?? [];
    if (! in_array(['$ref' => '#/components/parameters/InterviewIdempotencyKey'], $importParameters, true)
        || ($tier1['components']['parameters']['InterviewIdempotencyKey']['required'] ?? null) !== false) {
        $errors[] = 'Interview import must expose its optional bounded Idempotency-Key.';
    }
    foreach (['/v1/interviews', '/v1/interviews/{interview}/messages'] as $interviewWritePath) {
        $parameters = $tier1['paths'][$interviewWritePath]['post']['parameters'] ?? [];
        if (! in_array(['$ref' => '#/components/parameters/InterviewWriteIdempotencyKey'], $parameters, true)
            || ($tier1['components']['parameters']['InterviewWriteIdempotencyKey']['required'] ?? null) !== true
            || ($tier1['components']['parameters']['InterviewWriteIdempotencyKey']['schema']['minLength'] ?? null) !== 8
            || ($tier1['components']['parameters']['InterviewWriteIdempotencyKey']['schema']['maxLength'] ?? null) !== 200) {
            $errors[] = sprintf('Interview write POST %s must require its bounded replay-safe Idempotency-Key.', $interviewWritePath);
        }
        if (! isset($tier1['paths'][$interviewWritePath]['post']['responses']['409'])) {
            $errors[] = sprintf('Interview write POST %s must expose contract 409 conflicts.', $interviewWritePath);
        }
    }
    foreach ([['/v1/interviews', '201'], ['/v1/interviews/{interview}/messages', '200']] as [$interviewWritePath, $successStatus]) {
        $headerRef = $tier1['paths'][$interviewWritePath]['post']['responses'][$successStatus]['headers']['Idempotent-Replayed']['$ref'] ?? null;
        if ($headerRef !== '#/components/headers/IdempotentReplayed') {
            $errors[] = sprintf('Interview write POST %s response %s must expose replay metadata.', $interviewWritePath, $successStatus);
        }
    }
    if (($tier1['components']['responses']['InterviewProblem']['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/interview/problem.json') {
        $errors[] = 'Tier 1 Interview errors must use the Interview Problem schema.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Interview contracts: %s', $exception->getMessage());
}

$knowledgeDocuments = [
    'schemas/v1/knowledge/answer-request.json',
    'schemas/v1/knowledge/answer-response.json',
    'schemas/v1/knowledge/citation.json',
    'schemas/v1/knowledge/document-metadata.json',
    'schemas/v1/knowledge/document.json',
    'schemas/v1/knowledge/ingestion-job.json',
    'schemas/v1/knowledge/knowledge-base-create-request.json',
    'schemas/v1/knowledge/knowledge-base.json',
    'schemas/v1/knowledge/match.json',
    'schemas/v1/knowledge/problem.json',
    'schemas/v1/knowledge/retrieval-options.json',
    'schemas/v1/knowledge/search-request.json',
    'schemas/v1/knowledge/search-response.json',
];

foreach ($knowledgeDocuments as $relativePath) {
    if (! in_array($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath), $jsonFiles, true)) {
        $errors[] = sprintf('Missing Knowledge contract document %s', $relativePath);
    }
}

try {
    $knowledgeBaseCreate = json_decode((string) file_get_contents($root.'/schemas/v1/knowledge/knowledge-base-create-request.json'), true, flags: JSON_THROW_ON_ERROR);
    $knowledgeBase = json_decode((string) file_get_contents($root.'/schemas/v1/knowledge/knowledge-base.json'), true, flags: JSON_THROW_ON_ERROR);
    $documentMetadata = json_decode((string) file_get_contents($root.'/schemas/v1/knowledge/document-metadata.json'), true, flags: JSON_THROW_ON_ERROR);
    $document = json_decode((string) file_get_contents($root.'/schemas/v1/knowledge/document.json'), true, flags: JSON_THROW_ON_ERROR);
    $ingestionJob = json_decode((string) file_get_contents($root.'/schemas/v1/knowledge/ingestion-job.json'), true, flags: JSON_THROW_ON_ERROR);
    $retrievalOptions = json_decode((string) file_get_contents($root.'/schemas/v1/knowledge/retrieval-options.json'), true, flags: JSON_THROW_ON_ERROR);
    $searchRequest = json_decode((string) file_get_contents($root.'/schemas/v1/knowledge/search-request.json'), true, flags: JSON_THROW_ON_ERROR);
    $searchResponse = json_decode((string) file_get_contents($root.'/schemas/v1/knowledge/search-response.json'), true, flags: JSON_THROW_ON_ERROR);
    $match = json_decode((string) file_get_contents($root.'/schemas/v1/knowledge/match.json'), true, flags: JSON_THROW_ON_ERROR);
    $citation = json_decode((string) file_get_contents($root.'/schemas/v1/knowledge/citation.json'), true, flags: JSON_THROW_ON_ERROR);
    $answerRequest = json_decode((string) file_get_contents($root.'/schemas/v1/knowledge/answer-request.json'), true, flags: JSON_THROW_ON_ERROR);
    $answerResponse = json_decode((string) file_get_contents($root.'/schemas/v1/knowledge/answer-response.json'), true, flags: JSON_THROW_ON_ERROR);
    $knowledgeProblem = json_decode((string) file_get_contents($root.'/schemas/v1/knowledge/problem.json'), true, flags: JSON_THROW_ON_ERROR);

    foreach ([$knowledgeBaseCreate, $knowledgeBase, $documentMetadata, $document, $ingestionJob, $retrievalOptions, $searchRequest, $searchResponse, $match, $citation, $answerRequest, $answerResponse, $knowledgeProblem] as $knowledgeSchema) {
        if (($knowledgeSchema['additionalProperties'] ?? null) !== false) {
            $errors[] = sprintf('Knowledge schema %s must reject unknown root keys.', $knowledgeSchema['$id'] ?? 'unknown');
        }
    }

    if (($knowledgeBaseCreate['required'] ?? null) !== ['contract_version', 'name']
        || ($knowledgeBaseCreate['properties']['locale']['default'] ?? null) !== 'ja') {
        $errors[] = 'Knowledge base creation must require only contract_version and name while documenting the default locale ja.';
    }
    foreach (['tenant_id', 'id', 'retrieval_strategy', 'index_revision'] as $requiredField) {
        if (! in_array($requiredField, $knowledgeBase['required'] ?? [], true)) {
            $errors[] = sprintf('Knowledge base resources must require %s.', $requiredField);
        }
    }
    if (($knowledgeBase['properties']['retrieval_strategy']['const'] ?? null) !== 'weighted_rrf_v1') {
        $errors[] = 'Knowledge bases must expose weighted_rrf_v1 as their retrieval strategy.';
    }

    $expectedMetadataRequired = ['contract_version', 'original_filename', 'media_type', 'source_kind'];
    if (($documentMetadata['required'] ?? null) !== $expectedMetadataRequired
        || ($documentMetadata['properties']['tags']['maxItems'] ?? null) !== 20
        || ($documentMetadata['properties']['original_filename']['maxLength'] ?? null) !== 255) {
        $errors[] = 'Knowledge ingestion metadata must remain bounded and require only the version, filename, media type, and source kind.';
    }
    $forbiddenRequestProperty = static function (array $schema) use (&$forbiddenRequestProperty): ?string {
        foreach (array_keys($schema['properties'] ?? []) as $property) {
            if (preg_match('/(?:url|uri|credential|password|token|channel|message)/i', (string) $property) === 1) {
                return (string) $property;
            }
        }
        foreach ($schema as $value) {
            if (is_array($value)) {
                $forbidden = $forbiddenRequestProperty($value);
                if ($forbidden !== null) {
                    return $forbidden;
                }
            }
        }

        return null;
    };
    foreach ([$knowledgeBaseCreate, $documentMetadata, $searchRequest, $answerRequest] as $knowledgeRequest) {
        $forbidden = $forbiddenRequestProperty($knowledgeRequest);
        if ($forbidden !== null) {
            $errors[] = sprintf('Knowledge request schema %s must not expose remote-fetch, credential, or messaging property %s.', $knowledgeRequest['$id'] ?? 'unknown', $forbidden);
        }
    }

    foreach (['tenant_id', 'knowledge_base_id'] as $scopeField) {
        foreach ([$document, $ingestionJob, $searchResponse, $answerResponse] as $scopedResponse) {
            if (! in_array($scopeField, $scopedResponse['required'] ?? [], true)) {
                $errors[] = sprintf('Knowledge response %s must require scope field %s.', $scopedResponse['$id'] ?? 'unknown', $scopeField);
            }
        }
    }
    if (($document['properties']['byte_size']['maximum'] ?? null) !== 52428800
        || ($document['properties']['chunk_count']['maximum'] ?? null) !== 10000) {
        $errors[] = 'Knowledge documents must remain bounded to 50 MiB and 10,000 chunks.';
    }
    if (($ingestionJob['properties']['status']['enum'] ?? null) !== ['queued', 'running', 'succeeded', 'failed']
        || ($ingestionJob['properties']['stage']['enum'] ?? null) !== ['queued', 'extracting', 'chunking', 'indexing', 'complete']) {
        $errors[] = 'Knowledge ingestion must use the normalized asynchronous states and finite extraction stages.';
    }
    $jobError = $ingestionJob['properties']['error']['anyOf'][1] ?? [];
    if (($jobError['additionalProperties'] ?? null) !== false) {
        $errors[] = 'Knowledge ingestion job failures must use a closed error object.';
    }

    if (($retrievalOptions['properties']['strategy']['const'] ?? null) !== 'weighted_rrf_v1'
        || ($retrievalOptions['properties']['semantic_weight']['default'] ?? null) !== 70
        || ($retrievalOptions['properties']['rrf_k']['const'] ?? null) !== 60
        || ($retrievalOptions['properties']['top_k']['maximum'] ?? null) !== 50
        || ($retrievalOptions['properties']['filters']['additionalProperties'] ?? null) !== false) {
        $errors[] = 'Knowledge retrieval options must preserve the bounded deterministic weighted_rrf_v1 defaults and closed filters.';
    }
    if (($searchRequest['required'] ?? null) !== ['contract_version', 'query']
        || ($searchRequest['properties']['query']['maxLength'] ?? null) !== 4000) {
        $errors[] = 'Knowledge search must require a versioned query bounded to 4,000 characters.';
    }
    $ranking = $searchResponse['properties']['ranking'] ?? [];
    if (($ranking['additionalProperties'] ?? null) !== false
        || ($ranking['properties']['strategy']['const'] ?? null) !== 'weighted_rrf_v1'
        || ($ranking['properties']['rrf_k']['const'] ?? null) !== 60
        || ($ranking['properties']['tie_breaker']['const'] ?? null) !== 'fused_score_desc_chunk_id_asc') {
        $errors[] = 'Knowledge search responses must expose the deterministic fusion strategy, constant RRF k, and stable chunk-ID tie-break.';
    }
    if (($searchResponse['properties']['matches']['items']['$ref'] ?? null) !== 'match.json'
        || ($searchResponse['properties']['matches']['maxItems'] ?? null) !== 50
        || ($match['additionalProperties'] ?? null) !== false) {
        $errors[] = 'Knowledge search matches must use the closed bounded match contract.';
    }

    if (($answerRequest['properties']['grounding_policy']['const'] ?? null) !== 'strict_grounded'
        || ($answerRequest['properties']['grounding_policy']['default'] ?? null) !== 'strict_grounded') {
        $errors[] = 'Knowledge answer requests must permit and default to strict_grounded only.';
    }
    $answerOutcomes = [];
    foreach ($answerResponse['oneOf'] ?? [] as $outcome) {
        $outcomeName = $outcome['properties']['outcome']['const'] ?? null;
        if (is_string($outcomeName)) {
            $answerOutcomes[$outcomeName] = $outcome;
        }
    }
    if (($answerOutcomes['answered']['properties']['citations']['minItems'] ?? null) !== 1
        || ($answerOutcomes['answered']['properties']['refusal_reason']['type'] ?? null) !== 'null'
        || ($answerOutcomes['refused']['properties']['answer']['type'] ?? null) !== 'null'
        || ($answerOutcomes['refused']['properties']['citations']['maxItems'] ?? null) !== 0
        || ($citation['additionalProperties'] ?? null) !== false) {
        $errors[] = 'Knowledge answers must implement closed cite-or-refuse outcomes: answered requires citations and refused contains no answer or citations.';
    }

    $tier1 = json_decode((string) file_get_contents($root.'/openapi/tier1.json'), true, flags: JSON_THROW_ON_ERROR);
    $knowledgeOperations = [
        ['/v1/tenants/{tenant}/knowledge-bases', 'post', 'application/json', '../schemas/v1/knowledge/knowledge-base-create-request.json', '201', '../schemas/v1/knowledge/knowledge-base.json'],
        ['/v1/tenants/{tenant}/knowledge-bases/{knowledgeBase}', 'get', null, null, '200', '../schemas/v1/knowledge/knowledge-base.json'],
        ['/v1/tenants/{tenant}/knowledge-bases/{knowledgeBase}/ingestion-jobs', 'post', 'multipart/form-data', null, '202', '../schemas/v1/knowledge/ingestion-job.json'],
        ['/v1/tenants/{tenant}/knowledge-bases/{knowledgeBase}/ingestion-jobs/{ingestionJob}', 'get', null, null, '200', '../schemas/v1/knowledge/ingestion-job.json'],
        ['/v1/tenants/{tenant}/knowledge-bases/{knowledgeBase}/search', 'post', 'application/json', '../schemas/v1/knowledge/search-request.json', '200', '../schemas/v1/knowledge/search-response.json'],
        ['/v1/tenants/{tenant}/knowledge-bases/{knowledgeBase}/answers', 'post', 'application/json', '../schemas/v1/knowledge/answer-request.json', '200', '../schemas/v1/knowledge/answer-response.json'],
    ];
    foreach ($knowledgeOperations as [$path, $method, $requestContentType, $requestRef, $successStatus, $responseRef]) {
        $operation = $tier1['paths'][$path][$method] ?? [];
        if ($operation === []) {
            $errors[] = sprintf('Tier 1 OpenAPI is missing Knowledge operation %s %s.', strtoupper($method), $path);

            continue;
        }
        if (($operation['security'][0]['serviceBearer'] ?? null) !== []) {
            $errors[] = sprintf('Knowledge operation %s %s must require service Bearer authentication.', strtoupper($method), $path);
        }
        $pathParameters = $tier1['paths'][$path]['parameters'] ?? [];
        if (! in_array(['$ref' => '#/components/parameters/Tenant'], $pathParameters, true)) {
            $errors[] = sprintf('Knowledge path %s must be tenant scoped.', $path);
        }
        if (str_contains($path, '{knowledgeBase}') && ! in_array(['$ref' => '#/components/parameters/KnowledgeBase'], $pathParameters, true)) {
            $errors[] = sprintf('Knowledge path %s must resolve the knowledge base together with its tenant.', $path);
        }
        if ($requestContentType !== null && $requestRef !== null
            && ($operation['requestBody']['content'][$requestContentType]['schema']['$ref'] ?? null) !== $requestRef) {
            $errors[] = sprintf('Knowledge operation %s %s must use request schema %s.', strtoupper($method), $path, $requestRef);
        }
        if (($operation['responses'][$successStatus]['content']['application/json']['schema']['$ref'] ?? null) !== $responseRef) {
            $errors[] = sprintf('Knowledge operation %s %s response %s must use schema %s.', strtoupper($method), $path, $successStatus, $responseRef);
        }
    }

    $knowledgeStatuses = [
        ['/v1/tenants/{tenant}/knowledge-bases', 'post', ['201', '401', '409', '422'], '201'],
        ['/v1/tenants/{tenant}/knowledge-bases/{knowledgeBase}', 'get', ['200', '401', '404'], '200'],
        ['/v1/tenants/{tenant}/knowledge-bases/{knowledgeBase}/ingestion-jobs', 'post', ['202', '401', '404', '409', '413', '415', '422'], '202'],
        ['/v1/tenants/{tenant}/knowledge-bases/{knowledgeBase}/ingestion-jobs/{ingestionJob}', 'get', ['200', '401', '404'], '200'],
        ['/v1/tenants/{tenant}/knowledge-bases/{knowledgeBase}/search', 'post', ['200', '401', '404', '409', '422', '503'], '200'],
        ['/v1/tenants/{tenant}/knowledge-bases/{knowledgeBase}/answers', 'post', ['200', '401', '404', '409', '422', '503'], '200'],
    ];
    foreach ($knowledgeStatuses as [$path, $method, $expectedStatuses, $successStatus]) {
        $responses = $tier1['paths'][$path][$method]['responses'] ?? [];
        $actualStatuses = array_map(static fn (int|string $status): string => (string) $status, array_keys($responses));
        if ($actualStatuses !== $expectedStatuses) {
            $errors[] = sprintf('Knowledge operation %s %s must expose exactly statuses %s.', strtoupper($method), $path, implode(', ', $expectedStatuses));
        }
        foreach (array_diff($expectedStatuses, [$successStatus]) as $problemStatus) {
            if (($responses[$problemStatus]['$ref'] ?? null) !== '#/components/responses/KnowledgeProblem') {
                $errors[] = sprintf('Knowledge operation %s %s status %s must use the shared Knowledge Problem.', strtoupper($method), $path, $problemStatus);
            }
        }
    }

    $knowledgeBaseParameter = $tier1['components']['parameters']['KnowledgeBase'] ?? [];
    $knowledgeJobParameter = $tier1['components']['parameters']['KnowledgeIngestionJob'] ?? [];
    $knowledgeIdempotencyKey = $tier1['components']['parameters']['KnowledgeIdempotencyKey'] ?? [];
    if (($knowledgeBaseParameter['required'] ?? null) !== true
        || ($knowledgeBaseParameter['schema']['format'] ?? null) !== 'uuid'
        || ($knowledgeJobParameter['required'] ?? null) !== true
        || ($knowledgeJobParameter['schema']['format'] ?? null) !== 'uuid') {
        $errors[] = 'Knowledge base and ingestion job path parameters must be required UUIDs.';
    }
    if (($knowledgeIdempotencyKey['required'] ?? null) !== true
        || ($knowledgeIdempotencyKey['schema']['minLength'] ?? null) !== 8
        || ($knowledgeIdempotencyKey['schema']['maxLength'] ?? null) !== 200) {
        $errors[] = 'Knowledge writes must use a required Idempotency-Key bounded from 8 to 200 characters.';
    }
    $problemStatuses = $knowledgeProblem['properties']['status']['enum'] ?? [];
    sort($problemStatuses);
    if ($problemStatuses !== [401, 404, 409, 413, 415, 422, 503]) {
        $errors[] = 'Knowledge Problem statuses must exactly cover the documented authentication, scope, conflict, upload, validation, and availability failures.';
    }
    $expectedKnowledgeProblemTypes = [
        'unauthorized',
        'validation_failed',
        'idempotency_conflict',
        'idempotency_in_progress',
        'knowledge_base_not_found',
        'ingestion_job_not_found',
        'unsupported_media_type',
        'payload_too_large',
        'index_not_ready',
        'retrieval_failed',
        'answer_generation_failed',
    ];
    if (($knowledgeProblem['properties']['type']['enum'] ?? null) !== $expectedKnowledgeProblemTypes) {
        $errors[] = 'Knowledge Problem types must exactly cover authentication, validation, idempotency conflict/in-progress, scope, upload, retrieval, and answer failures.';
    }

    $ingestionPath = '/v1/tenants/{tenant}/knowledge-bases/{knowledgeBase}/ingestion-jobs';
    $ingestion = $tier1['paths'][$ingestionPath]['post'] ?? [];
    $multipart = $ingestion['requestBody']['content']['multipart/form-data'] ?? [];
    if (isset($ingestion['requestBody']['content']['application/json'])
        || ($multipart['schema']['additionalProperties'] ?? null) !== false
        || ($multipart['schema']['required'] ?? null) !== ['file', 'metadata']
        || ($multipart['schema']['properties']['file']['format'] ?? null) !== 'binary'
        || ($multipart['schema']['properties']['file']['maxLength'] ?? null) !== 52428800
        || ($multipart['schema']['properties']['metadata']['$ref'] ?? null) !== '../schemas/v1/knowledge/document-metadata.json') {
        $errors[] = 'Knowledge ingestion must accept only bounded multipart file bytes and the closed metadata schema.';
    }
    $expectedSourceBoundary = [
        'accepted_input' => 'multipart_file_bytes_and_bounded_metadata',
        'remote_url_fetch' => false,
        'source_credentials' => false,
        'messaging_surfaces' => false,
    ];
    if (($ingestion['x-magic-html-source-boundary'] ?? null) !== $expectedSourceBoundary) {
        $errors[] = 'Knowledge ingestion must expose the no-remote-fetch, no-credential, no-messaging source boundary.';
    }
    foreach (['/v1/tenants/{tenant}/knowledge-bases', $ingestionPath] as $idempotentKnowledgePath) {
        $operation = $tier1['paths'][$idempotentKnowledgePath]['post'] ?? [];
        if (! in_array(['$ref' => '#/components/parameters/KnowledgeIdempotencyKey'], $operation['parameters'] ?? [], true)) {
            $errors[] = sprintf('Knowledge write %s must require its bounded Idempotency-Key.', $idempotentKnowledgePath);
        }
        $status = $idempotentKnowledgePath === $ingestionPath ? '202' : '201';
        if (($operation['responses'][$status]['headers']['Idempotent-Replayed']['$ref'] ?? null) !== '#/components/headers/IdempotentReplayed') {
            $errors[] = sprintf('Knowledge write %s must expose the canonical idempotent replay header.', $idempotentKnowledgePath);
        }
    }

    $searchPath = '/v1/tenants/{tenant}/knowledge-bases/{knowledgeBase}/search';
    $expectedRetrievalBoundary = [
        'strategy' => 'weighted_rrf_v1',
        'semantic_rank_source' => 'indexed_embedding_similarity',
        'keyword_rank_source' => 'normalized_bm25',
        'rrf_k' => 60,
        'default_semantic_weight' => 70,
        'derived_keyword_weight' => '100-semantic_weight',
        'tie_breaker' => 'fused_score_desc_chunk_id_asc',
        'scope' => 'tenant_and_knowledge_base',
    ];
    if (($tier1['paths'][$searchPath]['post']['x-magic-html-retrieval'] ?? null) !== $expectedRetrievalBoundary) {
        $errors[] = 'Knowledge search must expose the fixed deterministic hybrid retrieval boundary.';
    }
    if (isset($tier1['paths'][$searchPath]['post']['parameters'])) {
        $errors[] = 'Synchronous Knowledge search must not require an Idempotency-Key.';
    }

    $answerPath = '/v1/tenants/{tenant}/knowledge-bases/{knowledgeBase}/answers';
    $expectedGroundingBoundary = [
        'default_policy' => 'strict_grounded',
        'allowed_policies' => ['strict_grounded'],
        'unsupported_answer_behavior' => 'refuse',
        'answered_requires_citations' => true,
        'scope' => 'tenant_and_knowledge_base',
    ];
    if (($tier1['paths'][$answerPath]['post']['x-magic-html-grounding'] ?? null) !== $expectedGroundingBoundary) {
        $errors[] = 'Knowledge answers must expose the strict cite-or-refuse grounding boundary.';
    }
    if (isset($tier1['paths'][$answerPath]['post']['parameters'])) {
        $errors[] = 'Synchronous Knowledge answering must not require an Idempotency-Key.';
    }

    foreach (array_keys($tier1['paths'] ?? []) as $path) {
        if (! str_contains($path, '/knowledge-bases')) {
            continue;
        }
        if (preg_match('~/(?:messages|channels|slack|line-works|remote-fetch)(?:/|$)~', $path) === 1) {
            $errors[] = sprintf('Knowledge must not expose messaging or caller-directed remote-fetch surface %s.', $path);
        }
    }
    if (($tier1['components']['responses']['KnowledgeProblem']['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/knowledge/problem.json') {
        $errors[] = 'Tier 1 Knowledge errors must use the closed Knowledge Problem schema.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Knowledge contracts: %s', $exception->getMessage());
}

$crmDocuments = [
    'schemas/v1/crm/company-create-request.json',
    'schemas/v1/crm/company-list-query.json',
    'schemas/v1/crm/company-list.json',
    'schemas/v1/crm/company-update-request.json',
    'schemas/v1/crm/company.json',
    'schemas/v1/crm/custom-fields.json',
    'schemas/v1/crm/delete-request.json',
    'schemas/v1/crm/deletion.json',
    'schemas/v1/crm/pagination-meta.json',
    'schemas/v1/crm/person-create-request.json',
    'schemas/v1/crm/person-list-query.json',
    'schemas/v1/crm/person-list.json',
    'schemas/v1/crm/person-update-request.json',
    'schemas/v1/crm/person.json',
    'schemas/v1/crm/problem.json',
];

foreach ($crmDocuments as $relativePath) {
    if (! in_array($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath), $jsonFiles, true)) {
        $errors[] = sprintf('Missing CRM contract document %s', $relativePath);
    }
}

try {
    $crmSchemas = [];
    foreach ($crmDocuments as $relativePath) {
        $crmSchemas[basename($relativePath)] = json_decode((string) file_get_contents($root.'/'.$relativePath), true, flags: JSON_THROW_ON_ERROR);
    }

    foreach ($crmSchemas as $filename => $crmSchema) {
        if ($filename === 'custom-fields.json') {
            continue;
        }
        if (($crmSchema['additionalProperties'] ?? null) !== false) {
            $errors[] = sprintf('CRM schema %s must reject unknown root keys.', $crmSchema['$id'] ?? $filename);
        }
    }

    $customFields = $crmSchemas['custom-fields.json'];
    $customMap = $customFields['anyOf'][0] ?? [];
    $customValueTypes = $customMap['additionalProperties']['anyOf'] ?? [];
    if (($customMap['type'] ?? null) !== 'object'
        || ($customMap['maxProperties'] ?? null) !== 25
        || ($customMap['propertyNames']['pattern'] ?? null) !== '^[A-Za-z][A-Za-z0-9_.-]{0,63}$'
        || ($customFields['x-magic-html-max-encoded-characters'] ?? null) !== 20000
        || ($customValueTypes[0]['type'] ?? null) !== 'string'
        || ($customValueTypes[0]['maxLength'] ?? null) !== 1000
        || array_column($customValueTypes, 'type') !== ['string', 'number', 'boolean', 'null']
        || ($customFields['anyOf'][1] ?? null) !== ['type' => 'array', 'maxItems' => 0]) {
        $errors[] = 'CRM custom fields must match the service bound of 25 safe scalar keys, 1,000-character strings, and 20,000 encoded characters.';
    }

    $company = $crmSchemas['company.json'];
    $person = $crmSchemas['person.json'];
    $companyCreate = $crmSchemas['company-create-request.json'];
    $companyUpdate = $crmSchemas['company-update-request.json'];
    $personCreate = $crmSchemas['person-create-request.json'];
    $personUpdate = $crmSchemas['person-update-request.json'];
    $deleteRequest = $crmSchemas['delete-request.json'];
    $deletion = $crmSchemas['deletion.json'];
    $companyQuery = $crmSchemas['company-list-query.json'];
    $personQuery = $crmSchemas['person-list-query.json'];
    $pagination = $crmSchemas['pagination-meta.json'];
    $crmProblem = $crmSchemas['problem.json'];

    $expectedCompanyFields = ['address', 'contract_version', 'corporate_number', 'created_at', 'custom_fields', 'email', 'id', 'industry', 'name', 'name_kana', 'notes', 'phone', 'postal_code', 'tenant_id', 'updated_at', 'version', 'website'];
    $actualCompanyFields = array_keys($company['properties'] ?? []);
    sort($actualCompanyFields);
    $companyRequired = $company['required'] ?? [];
    sort($companyRequired);
    if ($actualCompanyFields !== $expectedCompanyFields || $companyRequired !== $expectedCompanyFields) {
        $errors[] = 'CRM Company responses must expose and require exactly the fields emitted by CompanyResource.';
    }

    $expectedPersonFields = ['company_id', 'contract_version', 'created_at', 'custom_fields', 'department', 'email', 'id', 'mobile', 'name', 'name_kana', 'notes', 'phone', 'tenant_id', 'title', 'updated_at', 'version'];
    $actualPersonFields = array_keys($person['properties'] ?? []);
    sort($actualPersonFields);
    $personRequired = $person['required'] ?? [];
    sort($personRequired);
    if ($actualPersonFields !== $expectedPersonFields || $personRequired !== $expectedPersonFields) {
        $errors[] = 'CRM Person responses must expose and require exactly the fields emitted by PersonResource.';
    }
    if (($company['properties']['tenant_id']['pattern'] ?? null) !== '^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$'
        || ($person['properties']['tenant_id'] ?? null) !== ($company['properties']['tenant_id'] ?? null)
        || ($person['properties']['company_id']['type'] ?? null) !== ['string', 'null']
        || ! str_contains((string) ($person['properties']['company_id']['description'] ?? ''), 'active company in the same tenant')) {
        $errors[] = 'CRM resources must preserve tenant identity and the optional same-tenant active-company relationship.';
    }

    if (($companyCreate['required'] ?? null) !== ['name']
        || ($personCreate['required'] ?? null) !== ['name']
        || ($companyCreate['properties']['name']['maxLength'] ?? null) !== 200
        || ($personCreate['properties']['name']['maxLength'] ?? null) !== 200
        || ($companyCreate['properties']['corporate_number']['pattern'] ?? null) !== '^[0-9]{13}$'
        || ($companyCreate['properties']['website']['maxLength'] ?? null) !== 2048
        || ($personCreate['properties']['company_id']['format'] ?? null) !== 'uuid') {
        $errors[] = 'CRM create schemas must exactly preserve required names, company identifiers, URLs, and optional same-tenant company links.';
    }
    foreach ([$companyCreate, $companyUpdate, $personCreate, $personUpdate] as $crmMutation) {
        if (($crmMutation['properties']['custom_fields']['$ref'] ?? null) !== 'custom-fields.json') {
            $errors[] = sprintf('CRM mutation %s must use the bounded custom-field definition.', $crmMutation['$id'] ?? 'unknown');
        }
    }
    foreach ([$companyUpdate, $personUpdate, $deleteRequest] as $versionedRequest) {
        if (! in_array('expected_version', $versionedRequest['required'] ?? [], true)
            || ($versionedRequest['properties']['expected_version']['type'] ?? null) !== 'integer'
            || ($versionedRequest['properties']['expected_version']['minimum'] ?? null) !== 1) {
            $errors[] = sprintf('CRM update/delete schema %s must require expected_version >= 1.', $versionedRequest['$id'] ?? 'unknown');
        }
    }
    if (count($companyUpdate['anyOf'] ?? []) !== 11 || count($personUpdate['anyOf'] ?? []) !== 10) {
        $errors[] = 'CRM updates must require at least one mutable field in addition to expected_version.';
    }
    if (($deletion['required'] ?? null) !== ['contract_version', 'id', 'deleted']
        || ($deletion['properties']['deleted']['const'] ?? null) !== true) {
        $errors[] = 'CRM soft deletes must return the exact versioned deletion result.';
    }

    $expectedListQueryProperties = ['direction', 'page', 'per_page', 'q', 'sort'];
    $companyQueryProperties = array_keys($companyQuery['properties'] ?? []);
    sort($companyQueryProperties);
    $personQueryProperties = array_keys($personQuery['properties'] ?? []);
    sort($personQueryProperties);
    if ($companyQueryProperties !== $expectedListQueryProperties
        || $personQueryProperties !== ['company_id', 'direction', 'page', 'per_page', 'q', 'sort']
        || ($companyQuery['properties']['sort']['enum'] ?? null) !== ['created_at', 'updated_at', 'name']
        || ($companyQuery['properties']['sort']['default'] ?? null) !== 'created_at'
        || ($companyQuery['properties']['direction']['enum'] ?? null) !== ['asc', 'desc']
        || ($companyQuery['properties']['direction']['default'] ?? null) !== 'desc'
        || ($companyQuery['properties']['per_page']['minimum'] ?? null) !== 1
        || ($companyQuery['properties']['per_page']['maximum'] ?? null) !== 100
        || ($companyQuery['properties']['per_page']['default'] ?? null) !== 25
        || ($personQuery['properties']['company_id']['format'] ?? null) !== 'uuid') {
        $errors[] = 'CRM list queries must expose exactly the implemented bounded filters, finite sorting, direction, and pagination defaults.';
    }
    if (($pagination['required'] ?? null) !== ['current_page', 'per_page', 'total', 'last_page']
        || ($pagination['properties']['per_page']['maximum'] ?? null) !== 100
        || ($crmSchemas['company-list.json']['properties']['data']['items']['$ref'] ?? null) !== 'company.json'
        || ($crmSchemas['person-list.json']['properties']['data']['items']['$ref'] ?? null) !== 'person.json') {
        $errors[] = 'CRM list responses must use closed pagination metadata and their exact resource definitions.';
    }

    $expectedCrmProblemTypes = ['unauthenticated', 'validation_failed', 'crm_record_not_found', 'crm_version_conflict', 'idempotency_conflict', 'idempotency_in_progress'];
    if (($crmProblem['properties']['type']['enum'] ?? null) !== $expectedCrmProblemTypes
        || ($crmProblem['properties']['status']['enum'] ?? null) !== [401, 404, 409, 422]
        || ! in_array('current_version', $crmProblem['allOf'][0]['then']['required'] ?? [], true)
        || ! in_array('errors', $crmProblem['allOf'][1]['then']['required'] ?? [], true)) {
        $errors[] = 'CRM Problem must exactly cover authentication, validation, tenant-scoped not-found, stale version, and idempotency failures with their conditional context.';
    }

    $tier1 = json_decode((string) file_get_contents($root.'/openapi/tier1.json'), true, flags: JSON_THROW_ON_ERROR);
    $crmOperations = [
        ['/v1/tenants/{tenant}/crm/companies', 'get', 'listCrmCompanies', null, '200', '../schemas/v1/crm/company-list.json', ['200', '401', '422', '429']],
        ['/v1/tenants/{tenant}/crm/companies', 'post', 'createCrmCompany', '../schemas/v1/crm/company-create-request.json', '201', '../schemas/v1/crm/company.json', ['201', '401', '409', '422', '429']],
        ['/v1/tenants/{tenant}/crm/companies/{company}', 'get', 'getCrmCompany', null, '200', '../schemas/v1/crm/company.json', ['200', '401', '404', '429']],
        ['/v1/tenants/{tenant}/crm/companies/{company}', 'patch', 'updateCrmCompany', '../schemas/v1/crm/company-update-request.json', '200', '../schemas/v1/crm/company.json', ['200', '401', '404', '409', '422', '429']],
        ['/v1/tenants/{tenant}/crm/companies/{company}', 'delete', 'deleteCrmCompany', '../schemas/v1/crm/delete-request.json', '200', '../schemas/v1/crm/deletion.json', ['200', '401', '404', '409', '422', '429']],
        ['/v1/tenants/{tenant}/crm/people', 'get', 'listCrmPeople', null, '200', '../schemas/v1/crm/person-list.json', ['200', '401', '422', '429']],
        ['/v1/tenants/{tenant}/crm/people', 'post', 'createCrmPerson', '../schemas/v1/crm/person-create-request.json', '201', '../schemas/v1/crm/person.json', ['201', '401', '409', '422', '429']],
        ['/v1/tenants/{tenant}/crm/people/{person}', 'get', 'getCrmPerson', null, '200', '../schemas/v1/crm/person.json', ['200', '401', '404', '429']],
        ['/v1/tenants/{tenant}/crm/people/{person}', 'patch', 'updateCrmPerson', '../schemas/v1/crm/person-update-request.json', '200', '../schemas/v1/crm/person.json', ['200', '401', '404', '409', '422', '429']],
        ['/v1/tenants/{tenant}/crm/people/{person}', 'delete', 'deleteCrmPerson', '../schemas/v1/crm/delete-request.json', '200', '../schemas/v1/crm/deletion.json', ['200', '401', '404', '409', '422', '429']],
    ];
    foreach ($crmOperations as [$path, $method, $operationId, $requestRef, $successStatus, $responseRef, $expectedStatuses]) {
        $operation = $tier1['paths'][$path][$method] ?? [];
        if ($operation === []) {
            $errors[] = sprintf('Tier 1 OpenAPI is missing CRM operation %s %s.', strtoupper($method), $path);

            continue;
        }
        if (($operation['operationId'] ?? null) !== $operationId
            || ($operation['security'][0]['serviceBearer'] ?? null) !== []) {
            $errors[] = sprintf('CRM operation %s %s must have exact operationId %s and service Bearer authentication.', strtoupper($method), $path, $operationId);
        }
        if (! in_array(['$ref' => '#/components/parameters/Tenant'], $tier1['paths'][$path]['parameters'] ?? [], true)) {
            $errors[] = sprintf('CRM path %s must carry the tenant path scope.', $path);
        }
        $actualStatuses = array_map(static fn (int|string $status): string => (string) $status, array_keys($operation['responses'] ?? []));
        if ($actualStatuses !== $expectedStatuses) {
            $errors[] = sprintf('CRM operation %s %s must expose exactly statuses %s.', strtoupper($method), $path, implode(', ', $expectedStatuses));
        }
        if (($operation['responses'][$successStatus]['content']['application/json']['schema']['$ref'] ?? null) !== $responseRef) {
            $errors[] = sprintf('CRM operation %s %s success response must use %s.', strtoupper($method), $path, $responseRef);
        }
        foreach (array_intersect($expectedStatuses, ['401', '404', '409', '422']) as $problemStatus) {
            if (($operation['responses'][$problemStatus]['$ref'] ?? null) !== '#/components/responses/CrmProblem') {
                $errors[] = sprintf('CRM operation %s %s status %s must use the closed CRM Problem.', strtoupper($method), $path, $problemStatus);
            }
        }
        if ($requestRef === null) {
            if (isset($operation['requestBody']) || in_array(['$ref' => '#/components/parameters/CrmIdempotencyKey'], $operation['parameters'] ?? [], true)) {
                $errors[] = sprintf('Read-only CRM operation %s %s must not accept a write body or Idempotency-Key.', strtoupper($method), $path);
            }

            continue;
        }
        $content = $operation['requestBody']['content'] ?? [];
        if (($operation['requestBody']['required'] ?? null) !== true
            || array_keys($content) !== ['application/json']
            || ($content['application/json']['schema']['$ref'] ?? null) !== $requestRef
            || ! in_array(['$ref' => '#/components/parameters/CrmIdempotencyKey'], $operation['parameters'] ?? [], true)
            || ($operation['responses'][$successStatus]['headers']['Idempotent-Replayed']['$ref'] ?? null) !== '#/components/headers/IdempotentReplayed') {
            $errors[] = sprintf('CRM write %s %s must require its exact JSON schema, bounded Idempotency-Key, and canonical replay header.', strtoupper($method), $path);
        }
    }

    $crmPaths = array_values(array_filter(array_keys($tier1['paths'] ?? []), static fn (string $path): bool => str_contains($path, '/crm/')));
    if ($crmPaths !== ['/v1/tenants/{tenant}/crm/companies', '/v1/tenants/{tenant}/crm/companies/{company}', '/v1/tenants/{tenant}/crm/people', '/v1/tenants/{tenant}/crm/people/{person}']) {
        $errors[] = 'Tier 1 CRM must expose exactly the four implemented company/person resource paths.';
    }
    if (! in_array(['$ref' => '#/components/parameters/CrmCompany'], $tier1['paths']['/v1/tenants/{tenant}/crm/companies/{company}']['parameters'] ?? [], true)
        || ! in_array(['$ref' => '#/components/parameters/CrmPerson'], $tier1['paths']['/v1/tenants/{tenant}/crm/people/{person}']['parameters'] ?? [], true)) {
        $errors[] = 'CRM item paths must require UUID company/person identifiers resolved within tenant scope.';
    }
    $companyListParameters = $tier1['paths']['/v1/tenants/{tenant}/crm/companies']['get']['parameters'] ?? [];
    $peopleListParameters = $tier1['paths']['/v1/tenants/{tenant}/crm/people']['get']['parameters'] ?? [];
    $expectedCompanyListParameters = array_map(static fn (string $name): array => ['$ref' => '#/components/parameters/'.$name], ['CrmSearch', 'CrmSort', 'CrmDirection', 'CrmPage', 'CrmPerPage']);
    $expectedPeopleListParameters = array_map(static fn (string $name): array => ['$ref' => '#/components/parameters/'.$name], ['CrmSearch', 'CrmCompanyFilter', 'CrmSort', 'CrmDirection', 'CrmPage', 'CrmPerPage']);
    if ($companyListParameters !== $expectedCompanyListParameters || $peopleListParameters !== $expectedPeopleListParameters) {
        $errors[] = 'CRM list OpenAPI parameters must exactly mirror the implemented company and person query boundaries.';
    }
    $crmIdempotencyKey = $tier1['components']['parameters']['CrmIdempotencyKey'] ?? [];
    if (($crmIdempotencyKey['required'] ?? null) !== true
        || ($crmIdempotencyKey['schema']['minLength'] ?? null) !== 8
        || ($crmIdempotencyKey['schema']['maxLength'] ?? null) !== 200
        || ($tier1['components']['responses']['CrmProblem']['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/crm/problem.json') {
        $errors[] = 'Tier 1 CRM must expose its 8–200 character idempotency key and closed CRM Problem response.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect CRM contracts: %s', $exception->getMessage());
}

$messagingDocuments = [
    'schemas/v1/messaging/channel-create-request.json',
    'schemas/v1/messaging/channel-list-query.json',
    'schemas/v1/messaging/channel-list.json',
    'schemas/v1/messaging/channel-response.json',
    'schemas/v1/messaging/channel.json',
    'schemas/v1/messaging/conversation-create-request.json',
    'schemas/v1/messaging/conversation-list-query.json',
    'schemas/v1/messaging/conversation-list.json',
    'schemas/v1/messaging/conversation-response.json',
    'schemas/v1/messaging/conversation.json',
    'schemas/v1/messaging/message-create-request.json',
    'schemas/v1/messaging/message-list-query.json',
    'schemas/v1/messaging/message-list.json',
    'schemas/v1/messaging/message-response.json',
    'schemas/v1/messaging/message.json',
    'schemas/v1/messaging/metadata.json',
    'schemas/v1/messaging/pagination-links.json',
    'schemas/v1/messaging/pagination-meta.json',
    'schemas/v1/messaging/problem.json',
    'schemas/v1/messaging/slack-url-verification-response.json',
    'schemas/v1/messaging/slack-webhook-accepted-response.json',
    'schemas/v1/messaging/slack-webhook-request.json',
];

foreach ($messagingDocuments as $relativePath) {
    if (! in_array($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath), $jsonFiles, true)) {
        $errors[] = sprintf('Missing Messaging contract document %s', $relativePath);
    }
}

try {
    $messagingSchemas = [];
    foreach ($messagingDocuments as $relativePath) {
        $messagingSchemas[basename($relativePath)] = json_decode((string) file_get_contents($root.'/'.$relativePath), true, flags: JSON_THROW_ON_ERROR);
    }

    foreach ($messagingSchemas as $filename => $messagingSchema) {
        if (in_array($filename, ['metadata.json', 'slack-webhook-request.json', 'slack-webhook-accepted-response.json'], true)) {
            continue;
        }
        if (($messagingSchema['additionalProperties'] ?? null) !== false) {
            $errors[] = sprintf('Messaging schema %s must reject unknown root keys.', $messagingSchema['$id'] ?? $filename);
        }
    }

    $metadata = $messagingSchemas['metadata.json'];
    if (($metadata['anyOf'][0]['type'] ?? null) !== 'object'
        || ($metadata['anyOf'][0]['maxProperties'] ?? null) !== 20
        || ($metadata['anyOf'][0]['additionalProperties']['type'] ?? null) !== ['string', 'null']
        || ($metadata['anyOf'][0]['additionalProperties']['maxLength'] ?? null) !== 500
        || ($metadata['anyOf'][1]['type'] ?? null) !== 'array'
        || ($metadata['anyOf'][1]['maxItems'] ?? null) !== 20
        || ($metadata['anyOf'][1]['items']['type'] ?? null) !== ['string', 'null']) {
        $errors[] = 'Messaging metadata must exactly preserve the bounded string/null map-or-list behavior implemented by the service.';
    }

    $channelCreate = $messagingSchemas['channel-create-request.json'];
    $conversationCreate = $messagingSchemas['conversation-create-request.json'];
    $messageCreate = $messagingSchemas['message-create-request.json'];
    if (($channelCreate['required'] ?? null) !== ['provider', 'name', 'credential_reference']
        || ($channelCreate['properties']['provider']['enum'] ?? null) !== ['local', 'slack']
        || ($channelCreate['properties']['credential_reference']['writeOnly'] ?? null) !== true
        || ($channelCreate['properties']['credential_reference']['pattern'] ?? null) !== '^[A-Za-z0-9._:/-]+$'
        || ! in_array('external_channel_id', $channelCreate['allOf'][0]['then']['required'] ?? [], true)) {
        $errors[] = 'Messaging channel creation must keep the provider allowlist, write-only credential reference, and Slack external-channel requirement.';
    }
    if (($conversationCreate['properties']['external_conversation_id']['maxLength'] ?? null) !== 191
        || ($conversationCreate['properties']['subject']['maxLength'] ?? null) !== 200
        || ($messageCreate['required'] ?? null) !== ['text']
        || ($messageCreate['properties']['text']['minLength'] ?? null) !== 1
        || ($messageCreate['properties']['text']['maxLength'] ?? null) !== 10000
        || ($messageCreate['properties']['text']['pattern'] ?? null) !== '\\S') {
        $errors[] = 'Messaging conversation and message writes must preserve the exact provider-ID, subject, and non-blank text bounds.';
    }
    foreach ([$channelCreate, $conversationCreate, $messageCreate] as $messagingWrite) {
        if (($messagingWrite['additionalProperties'] ?? null) !== false) {
            $errors[] = sprintf('Messaging write %s must reject every unknown top-level field.', $messagingWrite['$id'] ?? 'unknown');
        }
        if (isset($messagingWrite['properties']['metadata']) && ($messagingWrite['properties']['metadata']['$ref'] ?? null) !== 'metadata.json') {
            $errors[] = sprintf('Messaging write %s must use the bounded metadata schema.', $messagingWrite['$id'] ?? 'unknown');
        }
    }

    $channel = $messagingSchemas['channel.json'];
    $conversation = $messagingSchemas['conversation.json'];
    $message = $messagingSchemas['message.json'];
    $expectedChannelFields = ['active', 'created_at', 'credentials_configured', 'external_channel_id', 'id', 'metadata', 'name', 'provider', 'tenant_id', 'updated_at'];
    $expectedConversationFields = ['channel_id', 'created_at', 'external_conversation_id', 'id', 'metadata', 'status', 'subject', 'tenant_id', 'updated_at'];
    $expectedMessageFields = ['conversation_id', 'created_at', 'delivery_status', 'direction', 'id', 'metadata', 'provider_message_id', 'sender_external_id', 'tenant_id', 'text', 'thread_external_id', 'updated_at'];
    foreach ([[$channel, $expectedChannelFields, 'channel'], [$conversation, $expectedConversationFields, 'conversation'], [$message, $expectedMessageFields, 'message']] as [$resource, $expectedFields, $resourceName]) {
        $properties = array_keys($resource['properties'] ?? []);
        sort($properties);
        $required = $resource['required'] ?? [];
        sort($required);
        if ($properties !== $expectedFields || $required !== $expectedFields) {
            $errors[] = sprintf('Messaging %s response must expose and require exactly the fields emitted by its API resource.', $resourceName);
        }
        foreach (['credential_reference', 'credentials', 'provider_payload', 'raw', 'crm_company_id', 'crm_person_id'] as $forbiddenField) {
            if (array_key_exists($forbiddenField, $resource['properties'] ?? [])) {
                $errors[] = sprintf('Messaging %s response must never expose %s.', $resourceName, $forbiddenField);
            }
        }
    }
    if (($channel['properties']['credentials_configured']['const'] ?? null) !== true
        || ($channel['properties']['tenant_id']['format'] ?? null) !== 'uuid'
        || ($conversation['properties']['status']['enum'] ?? null) !== ['open', 'closed']
        || ($message['properties']['direction']['enum'] ?? null) !== ['inbound', 'outbound']
        || ($message['properties']['delivery_status']['enum'] ?? null) !== ['sent', 'failed', 'unknown', 'received']) {
        $errors[] = 'Messaging resources must expose only the credential marker, UUID tenant scope, finite conversation status, normalized direction, and finite delivery outcomes.';
    }
    foreach (['channel-response.json' => 'channel.json', 'conversation-response.json' => 'conversation.json', 'message-response.json' => 'message.json'] as $wrapper => $resourceRef) {
        if (($messagingSchemas[$wrapper]['required'] ?? null) !== ['data']
            || ($messagingSchemas[$wrapper]['properties']['data']['$ref'] ?? null) !== $resourceRef) {
            $errors[] = sprintf('Messaging response wrapper %s must contain exactly %s under data.', $wrapper, $resourceRef);
        }
    }

    $channelQuery = $messagingSchemas['channel-list-query.json'];
    $conversationQuery = $messagingSchemas['conversation-list-query.json'];
    $messageQuery = $messagingSchemas['message-list-query.json'];
    foreach ([$channelQuery, $conversationQuery, $messageQuery] as $listQuery) {
        if (($listQuery['properties']['page']['minimum'] ?? null) !== 1
            || ($listQuery['properties']['page']['maximum'] ?? null) !== 10000
            || ($listQuery['properties']['page']['default'] ?? null) !== 1
            || ($listQuery['properties']['per_page']['minimum'] ?? null) !== 1
            || ($listQuery['properties']['per_page']['maximum'] ?? null) !== 50
            || ($listQuery['properties']['per_page']['default'] ?? null) !== 20) {
            $errors[] = sprintf('Messaging list query %s must enforce page 1..10000 and per_page 1..50 with service defaults.', $listQuery['$id'] ?? 'unknown');
        }
    }
    if (($channelQuery['properties']['provider']['enum'] ?? null) !== ['local', 'slack']
        || ($conversationQuery['properties']['status']['enum'] ?? null) !== ['open', 'closed']
        || ($messageQuery['properties']['direction']['enum'] ?? null) !== ['inbound', 'outbound']) {
        $errors[] = 'Messaging list filters must exactly preserve provider, conversation status, and message direction allowlists.';
    }
    $paginationLinks = $messagingSchemas['pagination-links.json'];
    $paginationMeta = $messagingSchemas['pagination-meta.json'];
    if (($paginationLinks['required'] ?? null) !== ['first', 'last', 'prev', 'next']
        || ($paginationMeta['required'] ?? null) !== ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total']
        || ($paginationMeta['properties']['links']['items']['additionalProperties'] ?? null) !== false
        || ($paginationMeta['properties']['per_page']['maximum'] ?? null) !== 50) {
        $errors[] = 'Messaging pagination must model the exact closed Laravel resource links and metadata shape with a maximum page size of 50.';
    }
    foreach (['channel-list.json' => 'channel.json', 'conversation-list.json' => 'conversation.json', 'message-list.json' => 'message.json'] as $listSchema => $itemRef) {
        $list = $messagingSchemas[$listSchema];
        if (($list['required'] ?? null) !== ['data', 'links', 'meta']
            || ($list['properties']['data']['maxItems'] ?? null) !== 50
            || ($list['properties']['data']['items']['$ref'] ?? null) !== $itemRef
            || ($list['properties']['links']['$ref'] ?? null) !== 'pagination-links.json'
            || ($list['properties']['meta']['$ref'] ?? null) !== 'pagination-meta.json') {
            $errors[] = sprintf('Messaging list %s must expose its exact bounded resource page.', $listSchema);
        }
    }

    $slackRequest = $messagingSchemas['slack-webhook-request.json'];
    foreach (['url_verification', 'event_callback', 'event', 'ignored_callback', 'authorization', 'file_reference'] as $closedDefinition) {
        if (($slackRequest['$defs'][$closedDefinition]['additionalProperties'] ?? null) !== false) {
            $errors[] = sprintf('Messaging Slack webhook definition %s must reject unknown fields.', $closedDefinition);
        }
    }
    if (($slackRequest['$defs']['url_verification']['properties']['challenge']['maxLength'] ?? null) !== 256
        || ($slackRequest['$defs']['event_callback']['properties']['event_id']['pattern'] ?? null) !== '^[A-Za-z0-9._:-]{1,191}$'
        || ($slackRequest['$defs']['event']['properties']['text']['maxLength'] ?? null) !== 10000) {
        $errors[] = 'Messaging Slack envelopes must preserve the exact challenge, event ID, and normalized text bounds.';
    }
    foreach ([
        $slackRequest['$defs']['event']['properties']['callback_url'] ?? [],
        $slackRequest['$defs']['event']['properties']['response_url'] ?? [],
        $slackRequest['$defs']['ignored_callback']['properties']['callback_url'] ?? [],
        $slackRequest['$defs']['ignored_callback']['properties']['response_url'] ?? [],
        $slackRequest['$defs']['file_reference']['properties']['url_private'] ?? [],
        $slackRequest['$defs']['file_reference']['properties']['permalink'] ?? [],
    ] as $providerUrl) {
        if (($providerUrl['x-magic-html-runnable'] ?? null) !== false) {
            $errors[] = 'Every provider-supplied Messaging URL must be explicitly non-runnable.';
        }
    }
    $slackAccepted = $messagingSchemas['slack-webhook-accepted-response.json'];
    foreach ($slackAccepted['oneOf'] ?? [] as $acceptedVariant) {
        if (($acceptedVariant['additionalProperties'] ?? null) !== false
            || ($acceptedVariant['properties']['accepted']['const'] ?? null) !== true) {
            $errors[] = 'Every accepted Slack response variant must be closed and explicitly accepted.';
        }
    }
    if (($messagingSchemas['slack-url-verification-response.json']['properties']['challenge']['maxLength'] ?? null) !== 256) {
        $errors[] = 'Messaging Slack URL verification must return only the bounded challenge.';
    }

    $messagingProblem = $messagingSchemas['problem.json'];
    $expectedMessagingProblemTypes = ['unauthenticated', 'validation_failed', 'resource_not_found', 'idempotency_conflict', 'idempotency_in_progress', 'resource_conflict', 'delivery_provider_unavailable', 'payload_too_large', 'invalid_signature', 'invalid_event', 'event_id_conflict', 'rate_limited'];
    if (($messagingProblem['properties']['type']['const'] ?? null) !== 'about:blank'
        || ($messagingProblem['properties']['title']['enum'] ?? null) !== $expectedMessagingProblemTypes
        || ($messagingProblem['properties']['status']['enum'] ?? null) !== [401, 404, 409, 413, 422, 429, 503]
        || ! in_array('errors', $messagingProblem['allOf'][0]['then']['required'] ?? [], true)) {
        $errors[] = 'Messaging Problem must exactly cover service authentication, validation, scope, idempotency, delivery, Slack verification/dedupe, limits, and throttling.';
    }

    $tier1 = json_decode((string) file_get_contents($root.'/openapi/tier1.json'), true, flags: JSON_THROW_ON_ERROR);
    $messagingOperations = [
        ['/v1/tenants/{tenant}/messaging/channels', 'get', 'listMessagingChannels', null, '200', '../schemas/v1/messaging/channel-list.json', ['200', '401', '422', '429']],
        ['/v1/tenants/{tenant}/messaging/channels', 'post', 'createMessagingChannel', '../schemas/v1/messaging/channel-create-request.json', '201', '../schemas/v1/messaging/channel-response.json', ['201', '401', '409', '422', '429']],
        ['/v1/tenants/{tenant}/messaging/channels/{channel}', 'get', 'getMessagingChannel', null, '200', '../schemas/v1/messaging/channel-response.json', ['200', '401', '404', '429']],
        ['/v1/tenants/{tenant}/messaging/channels/{channel}/conversations', 'get', 'listMessagingConversations', null, '200', '../schemas/v1/messaging/conversation-list.json', ['200', '401', '404', '422', '429']],
        ['/v1/tenants/{tenant}/messaging/channels/{channel}/conversations', 'post', 'createMessagingConversation', '../schemas/v1/messaging/conversation-create-request.json', '201', '../schemas/v1/messaging/conversation-response.json', ['201', '401', '404', '409', '422', '429']],
        ['/v1/tenants/{tenant}/messaging/channels/{channel}/conversations/{conversation}', 'get', 'getMessagingConversation', null, '200', '../schemas/v1/messaging/conversation-response.json', ['200', '401', '404', '429']],
        ['/v1/tenants/{tenant}/messaging/conversations/{conversation}/messages', 'get', 'listMessagingMessages', null, '200', '../schemas/v1/messaging/message-list.json', ['200', '401', '404', '422', '429']],
        ['/v1/tenants/{tenant}/messaging/conversations/{conversation}/messages', 'post', 'createMessagingMessage', '../schemas/v1/messaging/message-create-request.json', '201', '../schemas/v1/messaging/message-response.json', ['201', '401', '404', '409', '422', '429', '503']],
    ];
    foreach ($messagingOperations as [$path, $method, $operationId, $requestRef, $successStatus, $responseRef, $expectedStatuses]) {
        $operation = $tier1['paths'][$path][$method] ?? [];
        if (($operation['operationId'] ?? null) !== $operationId
            || ($operation['security'][0]['serviceBearer'] ?? null) !== []) {
            $errors[] = sprintf('Messaging service operation %s %s must have exact operationId %s and Bearer authentication.', strtoupper($method), $path, $operationId);
        }
        if (! in_array(['$ref' => '#/components/parameters/TenantUuid'], $tier1['paths'][$path]['parameters'] ?? [], true)) {
            $errors[] = sprintf('Messaging service path %s must carry UUID tenant scope.', $path);
        }
        $actualStatuses = array_map(static fn (int|string $status): string => (string) $status, array_keys($operation['responses'] ?? []));
        if ($actualStatuses !== $expectedStatuses) {
            $errors[] = sprintf('Messaging operation %s %s must expose exactly statuses %s.', strtoupper($method), $path, implode(', ', $expectedStatuses));
        }
        if (($operation['responses'][$successStatus]['content']['application/json']['schema']['$ref'] ?? null) !== $responseRef) {
            $errors[] = sprintf('Messaging operation %s %s success response must use %s.', strtoupper($method), $path, $responseRef);
        }
        foreach (array_diff($expectedStatuses, [$successStatus]) as $problemStatus) {
            if (($operation['responses'][$problemStatus]['$ref'] ?? null) !== '#/components/responses/MessagingProblem') {
                $errors[] = sprintf('Messaging operation %s %s status %s must use Messaging Problem.', strtoupper($method), $path, $problemStatus);
            }
        }
        if ($requestRef === null) {
            if (isset($operation['requestBody']) || in_array(['$ref' => '#/components/parameters/MessagingIdempotencyKey'], $operation['parameters'] ?? [], true)) {
                $errors[] = sprintf('Read-only Messaging operation %s %s must not accept a write body or Idempotency-Key.', strtoupper($method), $path);
            }

            continue;
        }
        $content = $operation['requestBody']['content'] ?? [];
        if (($operation['requestBody']['required'] ?? null) !== true
            || array_keys($content) !== ['application/json']
            || ($content['application/json']['schema']['$ref'] ?? null) !== $requestRef
            || ! in_array(['$ref' => '#/components/parameters/MessagingIdempotencyKey'], $operation['parameters'] ?? [], true)
            || ($operation['responses'][$successStatus]['headers']['Idempotent-Replayed']['$ref'] ?? null) !== '#/components/headers/IdempotentReplayed') {
            $errors[] = sprintf('Messaging write %s %s must require its exact JSON schema, idempotency key, and canonical replay header.', strtoupper($method), $path);
        }
    }

    $webhookPath = '/v1/tenants/{tenant}/messaging/channels/{channel}/webhooks/slack';
    $webhook = $tier1['paths'][$webhookPath]['post'] ?? [];
    if (($webhook['operationId'] ?? null) !== 'ingestSlackMessagingWebhook'
        || ($webhook['security'] ?? null) !== [['slackHmacV0' => []]]
        || in_array(['$ref' => '#/components/parameters/MessagingIdempotencyKey'], $webhook['parameters'] ?? [], true)
        || ! in_array(['$ref' => '#/components/parameters/SlackRequestTimestamp'], $webhook['parameters'] ?? [], true)
        || ! in_array(['$ref' => '#/components/parameters/SlackSignature'], $webhook['parameters'] ?? [], true)
        || ($webhook['requestBody']['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/messaging/slack-webhook-request.json'
        || ($webhook['responses']['200']['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/messaging/slack-url-verification-response.json'
        || ($webhook['responses']['202']['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/messaging/slack-webhook-accepted-response.json') {
        $errors[] = 'Messaging Slack webhook must use exact HMAC headers, provider-event dedupe rather than Idempotency-Key, and its closed request/success schemas.';
    }
    $webhookStatuses = array_map(static fn (int|string $status): string => (string) $status, array_keys($webhook['responses'] ?? []));
    if ($webhookStatuses !== ['200', '202', '401', '404', '409', '413', '422', '429']) {
        $errors[] = 'Messaging Slack webhook must expose exact challenge, acceptance, authentication, scope, dedupe, size, validation, and rate-limit statuses.';
    }
    foreach (['401', '404', '409', '413', '422', '429'] as $webhookProblemStatus) {
        if (($webhook['responses'][$webhookProblemStatus]['$ref'] ?? null) !== '#/components/responses/MessagingProblem') {
            $errors[] = sprintf('Messaging Slack webhook status %s must use Messaging Problem.', $webhookProblemStatus);
        }
    }
    $expectedWebhookSecurity = [
        'signature_version' => 'v0_hmac_sha256',
        'signature_base' => 'v0:timestamp:exact_raw_body',
        'maximum_timestamp_skew_seconds' => 300,
        'maximum_payload_bytes' => 262144,
        'signing_credential_scope' => 'active_channel_tenant_and_slack_provider',
        'dedupe_scope' => 'messaging_channel_and_provider_event_id',
        'dedupe_fingerprint' => 'sha256_exact_raw_body',
        'changed_replay_status' => 409,
        'provider_callback_execution' => false,
        'provider_urls_persisted' => false,
        'raw_payload_persisted' => false,
        'normalized_fields_only' => true,
    ];
    if (($webhook['x-magic-html-webhook-security'] ?? null) !== $expectedWebhookSecurity) {
        $errors[] = 'Messaging Slack webhook must expose its complete HMAC, replay, bounded payload, normalized persistence, and non-runnable callback boundary.';
    }
    $messageWrite = $tier1['paths']['/v1/tenants/{tenant}/messaging/conversations/{conversation}/messages']['post'] ?? [];
    $expectedDeliveryBoundary = [
        'providers' => ['local', 'slack'],
        'slack_endpoint' => 'https://slack.com/api/chat.postMessage',
        'provider_endpoints_server_owned' => true,
        'runtime_credential_resolution' => true,
        'credential_scope' => 'active_channel_tenant_and_provider',
        'credentials_or_provider_payloads_persisted' => false,
        'automatic_retry' => false,
        'confirmed_success_status' => 'sent',
        'definite_failure_status' => 'failed',
        'ambiguous_outcome_status' => 'unknown',
        'unknown_requires_reconciliation_before_retry' => true,
        'idempotent_replay_redelivers' => false,
    ];
    if (($messageWrite['x-magic-html-delivery-boundary'] ?? null) !== $expectedDeliveryBoundary) {
        $errors[] = 'Messaging outbound delivery must expose fixed provider endpoints, runtime credentials, no automatic retry, finite outcomes, and no redelivery on replay.';
    }
    $messagingPaths = array_values(array_filter(array_keys($tier1['paths'] ?? []), static fn (string $path): bool => str_contains($path, '/messaging/')));
    if ($messagingPaths !== [
        '/v1/tenants/{tenant}/messaging/channels',
        '/v1/tenants/{tenant}/messaging/channels/{channel}',
        '/v1/tenants/{tenant}/messaging/channels/{channel}/conversations',
        '/v1/tenants/{tenant}/messaging/channels/{channel}/conversations/{conversation}',
        '/v1/tenants/{tenant}/messaging/conversations/{conversation}/messages',
        '/v1/tenants/{tenant}/messaging/channels/{channel}/webhooks/slack',
    ]) {
        $errors[] = 'Tier 1 Messaging must expose exactly its nine operations across the six implemented paths.';
    }
    $messagingIdempotency = $tier1['components']['parameters']['MessagingIdempotencyKey'] ?? [];
    if (($messagingIdempotency['required'] ?? null) !== true
        || ($messagingIdempotency['schema']['minLength'] ?? null) !== 8
        || ($messagingIdempotency['schema']['maxLength'] ?? null) !== 200
        || ($messagingIdempotency['schema']['pattern'] ?? null) !== '^[A-Za-z0-9._:-]+$'
        || ($tier1['components']['parameters']['SlackRequestTimestamp']['schema']['pattern'] ?? null) !== '^[0-9]+$'
        || ($tier1['components']['parameters']['SlackSignature']['schema']['pattern'] ?? null) !== '^v0=[a-f0-9]{64}$'
        || ($tier1['components']['responses']['MessagingProblem']['content']['application/problem+json']['schema']['$ref'] ?? null) !== '../schemas/v1/messaging/problem.json') {
        $errors[] = 'Tier 1 Messaging must expose exact idempotency, Slack HMAC header, and application/problem+json definitions.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Messaging contracts: %s', $exception->getMessage());
}

$channelDocuments = [
    'schemas/v1/channel/connection-create-request.json',
    'schemas/v1/channel/connection-list-query.json',
    'schemas/v1/channel/connection-list.json',
    'schemas/v1/channel/connection-response.json',
    'schemas/v1/channel/connection-update-request.json',
    'schemas/v1/channel/connection-verification-response.json',
    'schemas/v1/channel/connection-version-request.json',
    'schemas/v1/channel/connection.json',
    'schemas/v1/channel/credentials.json',
    'schemas/v1/channel/deleted-response.json',
    'schemas/v1/channel/media-artifact-create-request.json',
    'schemas/v1/channel/media-artifact-response.json',
    'schemas/v1/channel/media-artifact.json',
    'schemas/v1/channel/pagination-meta.json',
    'schemas/v1/channel/problem.json',
    'schemas/v1/channel/provider-identity.json',
    'schemas/v1/channel/publication-content.json',
    'schemas/v1/channel/publication-create-request.json',
    'schemas/v1/channel/publication-error.json',
    'schemas/v1/channel/publication-list-query.json',
    'schemas/v1/channel/publication-list.json',
    'schemas/v1/channel/publication-response.json',
    'schemas/v1/channel/publication.json',
];

foreach ($channelDocuments as $relativePath) {
    if (! in_array($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath), $jsonFiles, true)) {
        $errors[] = sprintf('Missing Channel contract document %s', $relativePath);
    }
}

try {
    $channelSchemas = [];
    foreach ($channelDocuments as $relativePath) {
        $channelSchemas[basename($relativePath)] = json_decode((string) file_get_contents($root.'/'.$relativePath), true, flags: JSON_THROW_ON_ERROR);
    }

    foreach ($channelSchemas as $filename => $channelSchema) {
        if (($channelSchema['$schema'] ?? null) !== 'https://json-schema.org/draft/2020-12/schema') {
            $errors[] = sprintf('Channel schema %s must declare JSON Schema 2020-12.', $filename);
        }
        if (($channelSchema['additionalProperties'] ?? null) !== false) {
            $errors[] = sprintf('Channel schema %s must reject unknown root keys.', $channelSchema['$id'] ?? $filename);
        }
    }

    $expectedProviders = ['x', 'threads', 'instagram', 'line', 'youtube'];
    $connectionCreate = $channelSchemas['connection-create-request.json'];
    $connectionUpdate = $channelSchemas['connection-update-request.json'];
    $credentials = $channelSchemas['credentials.json'];
    $connection = $channelSchemas['connection.json'];
    if (($connectionCreate['required'] ?? null) !== ['name', 'provider', 'credentials']
        || ($connectionCreate['properties']['provider']['enum'] ?? null) !== $expectedProviders
        || ($connectionCreate['properties']['configuration']['maxProperties'] ?? null) !== 0
        || ($connectionUpdate['required'] ?? null) !== ['expected_version']
        || ($connectionUpdate['properties']['expected_version']['minimum'] ?? null) !== 1
        || count($connectionUpdate['anyOf'] ?? []) !== 4) {
        $errors[] = 'Channel connection writes must be closed, provider-bounded, configuration-empty, optimistic-versioned, and non-empty.';
    }
    foreach ($connectionCreate['definitions'] ?? [] as $definition) {
        if (($definition['additionalProperties'] ?? null) !== false) {
            $errors[] = 'Channel provider credential definitions must reject unknown keys.';
        }
    }
    if (($credentials['minProperties'] ?? null) !== 1
        || ($credentials['maxProperties'] ?? null) !== 1
        || ($credentials['properties']['access_token']['writeOnly'] ?? null) !== true
        || ($credentials['properties']['channel_access_token']['writeOnly'] ?? null) !== true
        || ($credentials['properties']['access_token']['maxLength'] ?? null) !== 8192) {
        $errors[] = 'Channel credentials must allow exactly one bounded write-only provider token.';
    }

    $expectedConnectionFields = ['id', 'tenant_id', 'name', 'provider', 'account_ref', 'configuration', 'has_credentials', 'version', 'credential_version', 'verification_status', 'verified_at', 'created_at', 'updated_at'];
    if (array_keys($connection['properties'] ?? []) !== $expectedConnectionFields
        || ($connection['required'] ?? null) !== $expectedConnectionFields
        || ($connection['properties']['provider']['enum'] ?? null) !== $expectedProviders
        || ($connection['properties']['has_credentials']['const'] ?? null) !== true
        || ($connection['properties']['verification_status']['enum'] ?? null) !== ['unverified', 'verified']) {
        $errors[] = 'Channel connection resources must expose the exact credential-redacted version and verification state.';
    }
    foreach (['credentials', 'access_token', 'channel_access_token', 'provider_payload', 'raw'] as $secretField) {
        if (isset($connection['properties'][$secretField])) {
            $errors[] = sprintf('Channel connection resources must not expose %s.', $secretField);
        }
    }

    $artifactCreate = $channelSchemas['media-artifact-create-request.json'];
    $artifact = $channelSchemas['media-artifact.json'];
    $expectedArtifactFields = ['id', 'tenant_id', 'mime_type', 'extension', 'size_bytes', 'sha256', 'status', 'expires_at', 'claimed_at', 'deleted_at', 'created_at'];
    if (($artifactCreate['required'] ?? null) !== ['video']
        || ($artifactCreate['properties']['video']['format'] ?? null) !== 'binary'
        || ($artifact['required'] ?? null) !== $expectedArtifactFields
        || array_keys($artifact['properties'] ?? []) !== $expectedArtifactFields
        || ($artifact['properties']['size_bytes']['maximum'] ?? null) !== 536870912
        || ($artifact['properties']['mime_type']['const'] ?? null) !== 'video/mp4'
        || ($artifact['properties']['extension']['const'] ?? null) !== 'mp4'
        || ($artifact['properties']['sha256']['pattern'] ?? null) !== '^[a-f0-9]{64}$'
        || ($artifact['properties']['status']['enum'] ?? null) !== ['available', 'claimed', 'retained', 'deleted']) {
        $errors[] = 'Channel private media must expose one bounded MP4 binary and safe immutable metadata only.';
    }
    foreach (['disk', 'path', 'url', 'download_url'] as $privateField) {
        if (isset($artifact['properties'][$privateField])) {
            $errors[] = sprintf('Channel private media resources must not expose %s.', $privateField);
        }
    }

    $publicationContent = $channelSchemas['publication-content.json'];
    $expectedContentDefinitions = ['x_content', 'threads_content', 'instagram_content', 'line_content', 'line_message', 'line_text_message', 'line_image_message', 'youtube_content'];
    if (array_keys($publicationContent['definitions'] ?? []) !== $expectedContentDefinitions) {
        $errors[] = 'Channel publication content must define exactly X, Threads, Instagram, LINE, and YouTube closed content shapes.';
    }
    foreach ($publicationContent['definitions'] ?? [] as $name => $definition) {
        if (str_ends_with((string) $name, '_content') || str_ends_with((string) $name, '_message')) {
            if (($definition['additionalProperties'] ?? null) !== false && $name !== 'line_message') {
                $errors[] = sprintf('Channel publication definition %s must reject unknown keys.', $name);
            }
        }
    }
    $youtubeContent = $publicationContent['definitions']['youtube_content'] ?? [];
    if (($youtubeContent['required'] ?? null) !== ['media_artifact_id', 'title']
        || array_keys($youtubeContent['properties'] ?? []) !== ['media_artifact_id', 'title', 'description', 'privacy_status', 'category_id']
        || ($youtubeContent['properties']['privacy_status']['enum'] ?? null) !== ['private', 'unlisted', 'public']
        || ($youtubeContent['properties']['title']['maxLength'] ?? null) !== 100) {
        $errors[] = 'Channel YouTube publication input must accept only a private artifact UUID and bounded video metadata.';
    }
    foreach (['url', 'path', 'tags', 'provider_payload'] as $forbiddenYoutubeField) {
        if (isset($youtubeContent['properties'][$forbiddenYoutubeField])) {
            $errors[] = sprintf('Channel YouTube publication must not accept caller field %s.', $forbiddenYoutubeField);
        }
    }
    $lineMessage = $publicationContent['definitions']['line_message']['oneOf'] ?? [];
    if (count($lineMessage) !== 2
        || ($publicationContent['definitions']['line_text_message']['required'] ?? null) !== ['type', 'text']
        || ($publicationContent['definitions']['line_image_message']['required'] ?? null) !== ['type', 'originalContentUrl', 'previewImageUrl']) {
        $errors[] = 'Channel LINE content must contain only bounded closed text or image message variants.';
    }

    $publication = $channelSchemas['publication.json'];
    $expectedPublicationFields = ['id', 'tenant_id', 'connection_id', 'provider', 'status', 'content', 'provider_content_id', 'permalink', 'error', 'started_at', 'finished_at', 'created_at', 'updated_at'];
    if (($publication['required'] ?? null) !== $expectedPublicationFields
        || array_keys($publication['properties'] ?? []) !== $expectedPublicationFields
        || ($publication['properties']['provider']['enum'] ?? null) !== $expectedProviders
        || ($publication['properties']['status']['enum'] ?? null) !== ['queued', 'dispatching', 'succeeded', 'failed', 'unknown']) {
        $errors[] = 'Channel publication resources must expose exact normalized provider and finite delivery states.';
    }
    foreach (['raw', 'provider_payload', 'credentials', 'access_token', 'channel_access_token'] as $forbiddenPublicationField) {
        if (isset($publication['properties'][$forbiddenPublicationField])) {
            $errors[] = sprintf('Channel publication resources must not expose %s.', $forbiddenPublicationField);
        }
    }

    $connectionQuery = $channelSchemas['connection-list-query.json'];
    $publicationQuery = $channelSchemas['publication-list-query.json'];
    if (array_keys($connectionQuery['properties'] ?? []) !== ['provider', 'page', 'per_page']
        || array_keys($publicationQuery['properties'] ?? []) !== ['connection_id', 'status', 'page', 'per_page']
        || ($connectionQuery['properties']['per_page']['maximum'] ?? null) !== 100
        || ($publicationQuery['properties']['status']['enum'] ?? null) !== ['queued', 'dispatching', 'succeeded', 'failed', 'unknown']) {
        $errors[] = 'Channel list query contracts must exactly match bounded provider, connection, status, and pagination filters.';
    }
    foreach (['connection-list.json', 'publication-list.json'] as $listSchema) {
        if (($channelSchemas[$listSchema]['required'] ?? null) !== ['data', 'meta']
            || ($channelSchemas[$listSchema]['properties']['data']['maxItems'] ?? null) !== 100
            || ($channelSchemas[$listSchema]['properties']['meta']['$ref'] ?? null) !== 'pagination-meta.json') {
            $errors[] = sprintf('Channel list schema %s must expose a bounded page and exact pagination metadata.', $listSchema);
        }
    }

    $channelProblem = $channelSchemas['problem.json'];
    $expectedChannelProblemTypes = ['unauthenticated', 'validation_failed', 'channel_record_not_found', 'idempotency_conflict', 'idempotency_in_progress', 'channel_version_conflict', 'invalid_credentials', 'invalid_connection', 'invalid_content', 'provider_rejected', 'provider_unavailable', 'provider_rate_limited', 'unsupported_provider', 'delivery_unknown', 'invalid_provider_response', 'payload_too_large', 'rate_limited'];
    if (($channelProblem['properties']['type']['enum'] ?? null) !== $expectedChannelProblemTypes
        || ($channelProblem['properties']['status']['enum'] ?? null) !== [401, 404, 409, 413, 422, 429, 503]
        || ! in_array('errors', $channelProblem['allOf'][0]['then']['required'] ?? [], true)
        || ! in_array('current_version', $channelProblem['allOf'][1]['then']['required'] ?? [], true)) {
        $errors[] = 'Channel Problem must exactly cover authentication, validation, tenant scope, idempotency, version, provider, payload, and throttling failures.';
    }

    $tier1 = json_decode((string) file_get_contents($root.'/openapi/tier1.json'), true, flags: JSON_THROW_ON_ERROR);
    $channelOperations = [
        ['/v1/tenants/{tenant}/channels/connections', 'get', 'listChannelConnections', null, null, '200', '../schemas/v1/channel/connection-list.json', ['200', '401', '422', '429']],
        ['/v1/tenants/{tenant}/channels/connections', 'post', 'createChannelConnection', 'application/json', '../schemas/v1/channel/connection-create-request.json', '201', '../schemas/v1/channel/connection-response.json', ['201', '401', '409', '422', '429']],
        ['/v1/tenants/{tenant}/channels/connections/{connection}', 'get', 'getChannelConnection', null, null, '200', '../schemas/v1/channel/connection-response.json', ['200', '401', '404', '429']],
        ['/v1/tenants/{tenant}/channels/connections/{connection}', 'patch', 'updateChannelConnection', 'application/json', '../schemas/v1/channel/connection-update-request.json', '200', '../schemas/v1/channel/connection-response.json', ['200', '401', '404', '409', '422', '429']],
        ['/v1/tenants/{tenant}/channels/connections/{connection}', 'delete', 'deleteChannelConnection', 'application/json', '../schemas/v1/channel/connection-version-request.json', '200', '../schemas/v1/channel/deleted-response.json', ['200', '401', '404', '409', '422', '429']],
        ['/v1/tenants/{tenant}/channels/connections/{connection}/verify', 'post', 'verifyChannelConnection', 'application/json', '../schemas/v1/channel/connection-version-request.json', '200', '../schemas/v1/channel/connection-verification-response.json', ['200', '401', '404', '409', '422', '429', '503']],
        ['/v1/tenants/{tenant}/channels/media-artifacts', 'post', 'createChannelMediaArtifact', 'multipart/form-data', '../schemas/v1/channel/media-artifact-create-request.json', '201', '../schemas/v1/channel/media-artifact-response.json', ['201', '401', '409', '413', '422', '429', '503']],
        ['/v1/tenants/{tenant}/channels/media-artifacts/{artifact}', 'get', 'getChannelMediaArtifact', null, null, '200', '../schemas/v1/channel/media-artifact-response.json', ['200', '401', '404', '429']],
        ['/v1/tenants/{tenant}/channels/publications', 'get', 'listChannelPublications', null, null, '200', '../schemas/v1/channel/publication-list.json', ['200', '401', '422', '429']],
        ['/v1/tenants/{tenant}/channels/publications', 'post', 'createChannelPublication', 'application/json', '../schemas/v1/channel/publication-create-request.json', '202', '../schemas/v1/channel/publication-response.json', ['202', '401', '404', '409', '422', '429', '503']],
        ['/v1/tenants/{tenant}/channels/publications/{publication}', 'get', 'getChannelPublication', null, null, '200', '../schemas/v1/channel/publication-response.json', ['200', '401', '404', '429']],
    ];
    foreach ($channelOperations as [$path, $method, $operationId, $contentType, $requestRef, $successStatus, $responseRef, $expectedStatuses]) {
        $operation = $tier1['paths'][$path][$method] ?? [];
        $actualStatuses = array_map(static fn (int|string $status): string => (string) $status, array_keys($operation['responses'] ?? []));
        if (($operation['operationId'] ?? null) !== $operationId
            || ($operation['security'] ?? null) !== [['serviceBearer' => []]]
            || $actualStatuses !== $expectedStatuses
            || ($operation['responses'][$successStatus]['content']['application/json']['schema']['$ref'] ?? null) !== $responseRef) {
            $errors[] = sprintf('Tier 1 Channel operation %s must expose its exact ID, Bearer scope, statuses, and success schema.', $operationId);
        }
        if ($contentType !== null && ($operation['requestBody']['content'][$contentType]['schema']['$ref'] ?? null) !== $requestRef) {
            $errors[] = sprintf('Tier 1 Channel operation %s must expose only its exact %s request schema.', $operationId, $contentType);
        }
        foreach (array_diff($expectedStatuses, [$successStatus]) as $problemStatus) {
            if (($operation['responses'][$problemStatus]['$ref'] ?? null) !== '#/components/responses/ChannelProblem') {
                $errors[] = sprintf('Tier 1 Channel operation %s status %s must use Channel Problem.', $operationId, $problemStatus);
            }
        }
    }

    $channelWrites = [
        ['/v1/tenants/{tenant}/channels/connections', 'post', '201'],
        ['/v1/tenants/{tenant}/channels/connections/{connection}', 'patch', '200'],
        ['/v1/tenants/{tenant}/channels/connections/{connection}', 'delete', '200'],
        ['/v1/tenants/{tenant}/channels/connections/{connection}/verify', 'post', '200'],
        ['/v1/tenants/{tenant}/channels/media-artifacts', 'post', '201'],
        ['/v1/tenants/{tenant}/channels/publications', 'post', '202'],
    ];
    foreach ($channelWrites as [$path, $method, $status]) {
        $operation = $tier1['paths'][$path][$method] ?? [];
        if (! in_array(['$ref' => '#/components/parameters/ChannelIdempotencyKey'], $operation['parameters'] ?? [], true)
            || ($operation['responses'][$status]['headers']['Idempotent-Replayed']['$ref'] ?? null) !== '#/components/headers/IdempotentReplayed') {
            $errors[] = sprintf('Channel write %s %s must require its bounded key and expose canonical replay state.', strtoupper($method), $path);
        }
    }
    $expectedVersionRequests = [
        ['/v1/tenants/{tenant}/channels/connections/{connection}', 'patch', '../schemas/v1/channel/connection-update-request.json'],
        ['/v1/tenants/{tenant}/channels/connections/{connection}', 'delete', '../schemas/v1/channel/connection-version-request.json'],
        ['/v1/tenants/{tenant}/channels/connections/{connection}/verify', 'post', '../schemas/v1/channel/connection-version-request.json'],
    ];
    foreach ($expectedVersionRequests as [$path, $method, $requestRef]) {
        if (($tier1['paths'][$path][$method]['requestBody']['content']['application/json']['schema']['$ref'] ?? null) !== $requestRef) {
            $errors[] = sprintf('Channel optimistic write %s %s must require expected_version through its exact schema.', strtoupper($method), $path);
        }
    }

    $channelPaths = array_values(array_filter(array_keys($tier1['paths'] ?? []), static fn (string $path): bool => str_starts_with($path, '/v1/tenants/{tenant}/channels/')));
    if ($channelPaths !== [
        '/v1/tenants/{tenant}/channels/connections',
        '/v1/tenants/{tenant}/channels/connections/{connection}',
        '/v1/tenants/{tenant}/channels/connections/{connection}/verify',
        '/v1/tenants/{tenant}/channels/media-artifacts',
        '/v1/tenants/{tenant}/channels/media-artifacts/{artifact}',
        '/v1/tenants/{tenant}/channels/publications',
        '/v1/tenants/{tenant}/channels/publications/{publication}',
    ]) {
        $errors[] = 'Tier 1 Channel must expose exactly its eleven scoped operations across seven paths.';
    }
    $channelIdempotency = $tier1['components']['parameters']['ChannelIdempotencyKey'] ?? [];
    if (($channelIdempotency['required'] ?? null) !== true
        || ($channelIdempotency['schema']['minLength'] ?? null) !== 8
        || ($channelIdempotency['schema']['maxLength'] ?? null) !== 200
        || ($tier1['components']['responses']['ChannelProblem']['content']['application/problem+json']['schema']['$ref'] ?? null) !== '../schemas/v1/channel/problem.json') {
        $errors[] = 'Tier 1 Channel must expose its exact idempotency and application/problem+json components.';
    }

    $verificationBoundary = $tier1['paths']['/v1/tenants/{tenant}/channels/connections/{connection}/verify']['post']['x-magic-html-channel-verification-boundary'] ?? [];
    if ($verificationBoundary !== [
        'provider_endpoints_server_owned' => true,
        'credentials_encrypted_and_redacted' => true,
        'read_only_identity_lookup' => true,
        'youtube_channels_list_mine' => true,
        'verified_credential_version_must_match' => true,
    ]) {
        $errors[] = 'Channel verification must expose the fixed-endpoint, encrypted-credential, read-only identity boundary.';
    }
    $mediaBoundary = $tier1['paths']['/v1/tenants/{tenant}/channels/media-artifacts']['post']['x-magic-html-channel-media-boundary'] ?? [];
    if ($mediaBoundary !== [
        'storage' => 'private',
        'maximum_bytes' => 536870912,
        'accepted_mime_types' => ['video/mp4', 'application/mp4'],
        'accepted_extension' => 'mp4',
        'iso_base_media_ftyp_required' => true,
        'streamed_storage' => true,
        'caller_path_or_url_fetch' => false,
        'idempotency_fingerprint' => ['sha256', 'size_bytes', 'normalized_mime_type', 'extension'],
        'available_retention_hours' => 24,
        'unknown_retention_hours' => 24,
    ]) {
        $errors[] = 'Channel private media must expose its complete bounded upload, private retention, and no-fetch boundary.';
    }
    $publicationWrite = $tier1['paths']['/v1/tenants/{tenant}/channels/publications']['post'] ?? [];
    if (($publicationWrite['x-magic-html-channel-delivery-boundary'] ?? null) !== [
        'providers' => ['x', 'threads', 'instagram', 'line', 'youtube'],
        'provider_endpoints_server_owned' => true,
        'credentials_encrypted_and_redacted' => true,
        'verified_current_credentials_required' => true,
        'queue_job_tries' => 1,
        'automatic_retry_after_dispatch' => false,
        'confirmed_success_status' => 'succeeded',
        'definite_failure_status' => 'failed',
        'ambiguous_outcome_status' => 'unknown',
        'unknown_requires_reconciliation_before_new_publication' => true,
        'idempotent_replay_redelivers' => false,
    ]) {
        $errors[] = 'Channel delivery must expose exact providers, verified credentials, single-attempt delivery, finite outcomes, and no replay delivery.';
    }
    if (($publicationWrite['x-magic-html-youtube-resumable-boundary'] ?? null) !== [
        'session_endpoint' => 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet%2Cstatus',
        'private_media_artifact_required' => true,
        'artifact_sha256_in_idempotency_fingerprint' => true,
        'returned_location_https_only' => true,
        'returned_location_exact_host' => 'www.googleapis.com',
        'returned_location_port' => 443,
        'returned_location_expected_upload_path' => true,
        'returned_location_credentials_or_fragment_allowed' => false,
        'single_streamed_put' => true,
        'automatic_status_query_or_resume' => false,
        'ambiguous_delivery_status' => 'unknown',
    ]) {
        $errors[] = 'Channel YouTube delivery must expose its fixed initiation, strict returned Location, streamed PUT, and no-resume ambiguity boundary.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Channel contracts: %s', $exception->getMessage());
}

$measurementDocuments = [
    'schemas/v1/measurement/connection-create-request.json',
    'schemas/v1/measurement/connection-list-query.json',
    'schemas/v1/measurement/connection-list.json',
    'schemas/v1/measurement/connection-response.json',
    'schemas/v1/measurement/connection-update-request.json',
    'schemas/v1/measurement/connection.json',
    'schemas/v1/measurement/ga4-report-request.json',
    'schemas/v1/measurement/gbp-performance-report-request.json',
    'schemas/v1/measurement/gsc-report-request.json',
    'schemas/v1/measurement/mutation-confirmation.json',
    'schemas/v1/measurement/mutation-request.json',
    'schemas/v1/measurement/mutation-response.json',
    'schemas/v1/measurement/oauth-credentials.json',
    'schemas/v1/measurement/pagination-links.json',
    'schemas/v1/measurement/pagination-meta.json',
    'schemas/v1/measurement/problem.json',
    'schemas/v1/measurement/provider-configuration.json',
    'schemas/v1/measurement/rate-limit-response.json',
    'schemas/v1/measurement/report-response.json',
    'schemas/v1/measurement/resource-list-query.json',
    'schemas/v1/measurement/resource-list-response.json',
    'schemas/v1/measurement/service-account-credentials.json',
    'schemas/v1/measurement/verification-response.json',
];

foreach ($measurementDocuments as $relativePath) {
    if (! in_array($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath), $jsonFiles, true)) {
        $errors[] = sprintf('Missing Measurement contract document %s', $relativePath);
    }
}

try {
    $measurementSchemas = [];
    foreach ($measurementDocuments as $relativePath) {
        $measurementSchemas[basename($relativePath)] = json_decode((string) file_get_contents($root.'/'.$relativePath), true, flags: JSON_THROW_ON_ERROR);
    }

    foreach ($measurementSchemas as $filename => $measurementSchema) {
        if (($measurementSchema['$schema'] ?? null) !== 'https://json-schema.org/draft/2020-12/schema'
            || ($measurementSchema['$id'] ?? null) !== 'https://contracts.magic-html.dev/v1/measurement/'.$filename) {
            $errors[] = sprintf('Measurement schema %s must declare its exact JSON Schema 2020-12 identity.', $filename);
        }
        if ($filename !== 'provider-configuration.json' && ($measurementSchema['additionalProperties'] ?? null) !== false) {
            $errors[] = sprintf('Measurement schema %s must reject unknown root keys.', $filename);
        }
    }
    foreach ($measurementSchemas['provider-configuration.json']['oneOf'] ?? [] as $providerConfiguration) {
        if (($providerConfiguration['additionalProperties'] ?? null) !== false) {
            $errors[] = 'Every Measurement provider configuration variant must reject unknown keys.';
        }
    }

    $connectionCreate = $measurementSchemas['connection-create-request.json'];
    $connectionUpdate = $measurementSchemas['connection-update-request.json'];
    $connection = $measurementSchemas['connection.json'];
    $expectedConnectionFields = ['contract_version', 'id', 'tenant_id', 'name', 'provider', 'auth_mode', 'configuration', 'credentials_configured', 'verification_status', 'verified_at', 'created_at', 'updated_at'];
    if (($connectionCreate['required'] ?? null) !== ['contract_version', 'name', 'provider', 'auth_mode', 'configuration', 'credentials']
        || ($connectionCreate['properties']['provider']['enum'] ?? null) !== ['ga4', 'gsc', 'gtm', 'gbp']
        || count($connectionCreate['allOf'] ?? []) !== 6
        || ($connectionUpdate['required'] ?? null) !== ['contract_version']
        || array_keys($connectionUpdate['properties'] ?? []) !== ['contract_version', 'name', 'configuration', 'credentials']
        || ($connection['required'] ?? null) !== $expectedConnectionFields
        || array_keys($connection['properties'] ?? []) !== $expectedConnectionFields
        || ($connection['properties']['verification_status']['enum'] ?? null) !== ['unverified', 'verified', 'failed']) {
        $errors[] = 'Measurement connection schemas must exactly model provider/auth pairing, immutable provider identity, and credential-redacted responses.';
    }
    foreach (['credentials', 'client_id', 'client_secret', 'refresh_token', 'client_email', 'private_key', 'token_uri', 'connection_revision', 'verification_error'] as $forbiddenConnectionField) {
        if (isset($connection['properties'][$forbiddenConnectionField])) {
            $errors[] = sprintf('Measurement connection responses must not expose %s.', $forbiddenConnectionField);
        }
    }
    foreach (['oauth-credentials.json', 'service-account-credentials.json'] as $credentialsSchema) {
        foreach ($measurementSchemas[$credentialsSchema]['properties'] ?? [] as $credential) {
            if (($credential['writeOnly'] ?? null) !== true || ($credential['maxLength'] ?? 16384) > 16384) {
                $errors[] = sprintf('Every Measurement credential in %s must be write-only and bounded.', $credentialsSchema);
            }
        }
    }

    $connectionQuery = $measurementSchemas['connection-list-query.json'];
    $resourceQuery = $measurementSchemas['resource-list-query.json'];
    if (array_keys($connectionQuery['properties'] ?? []) !== ['page', 'per_page']
        || ($connectionQuery['properties']['page']['minimum'] ?? null) !== 1
        || ($connectionQuery['properties']['per_page']['maximum'] ?? null) !== 100
        || ($measurementSchemas['connection-list.json']['properties']['data']['maxItems'] ?? null) !== 100
        || ($resourceQuery['required'] ?? null) !== ['kind']
        || ($resourceQuery['properties']['page_size']['maximum'] ?? null) !== 100
        || ($resourceQuery['properties']['page_token']['maxLength'] ?? null) !== 2048) {
        $errors[] = 'Measurement list queries must be closed and enforce exact bounded connection and provider pagination.';
    }

    $ga4Report = $measurementSchemas['ga4-report-request.json'];
    $gscReport = $measurementSchemas['gsc-report-request.json'];
    $gbpReport = $measurementSchemas['gbp-performance-report-request.json'];
    if (($ga4Report['properties']['dimensions']['maxItems'] ?? null) !== 9
        || ($ga4Report['properties']['metrics']['maxItems'] ?? null) !== 10
        || ($ga4Report['properties']['limit']['maximum'] ?? null) !== 5000
        || ($ga4Report['properties']['offset']['maximum'] ?? null) !== 100000
        || ($gscReport['properties']['dimensions']['maxItems'] ?? null) !== 5
        || ($gscReport['properties']['row_limit']['maximum'] ?? null) !== 5000
        || ($gscReport['properties']['start_row']['maximum'] ?? null) !== 100000
        || ($gbpReport['properties']['metrics']['maxItems'] ?? null) !== 10) {
        $errors[] = 'Measurement report schemas must enforce the exact date, dimension, metric, row, and offset boundaries.';
    }

    $mutation = $measurementSchemas['mutation-request.json'];
    $confirmation = $measurementSchemas['mutation-confirmation.json'];
    if (($mutation['required'] ?? null) !== ['contract_version', 'operation', 'resource', 'expected_version', 'payload', 'confirmation']
        || ($mutation['properties']['operation']['enum'] ?? null) !== ['gtm.update_tag', 'gtm.publish_version', 'gbp.update_location', 'gbp.reply_review']
        || ($mutation['properties']['expected_version']['maxLength'] ?? null) !== 128
        || ($mutation['properties']['payload']['type'] ?? null) !== 'object'
        || ($mutation['properties']['payload']['maxProperties'] ?? null) !== 50
        || count($mutation['allOf'] ?? []) !== 4
        || ($confirmation['required'] ?? null) !== ['confirmed', 'summary_digest']
        || ($confirmation['properties']['confirmed']['const'] ?? null) !== true
        || ($confirmation['properties']['summary_digest']['pattern'] ?? null) !== '^[a-f0-9]{64}$') {
        $errors[] = 'Measurement mutations must exactly require a bounded operation, live version, closed payload root, and canonical confirmation digest.';
    }
    $mutationDefinitions = $mutation['$defs'] ?? [];
    if (($mutationDefinitions['gtm_tag_payload']['minProperties'] ?? null) !== 1
        || ($mutationDefinitions['gtm_tag_payload']['additionalProperties'] ?? null) !== false
        || ($mutationDefinitions['gtm_trigger_ids']['maxItems'] ?? null) !== 100
        || ($mutationDefinitions['gtm_parameter_list_0']['maxItems'] ?? null) !== 100
        || isset($mutationDefinitions['gtm_parameter_4']['properties']['list'])
        || ($mutationDefinitions['gbp_location_payload']['minProperties'] ?? null) !== 1
        || ($mutationDefinitions['gbp_location_payload']['additionalProperties'] ?? null) !== false
        || ($mutationDefinitions['gbp_phone_numbers']['properties']['additionalPhones']['maxItems'] ?? null) !== 10
        || ($mutationDefinitions['gbp_regular_hours']['properties']['periods']['maxItems'] ?? null) !== 14
        || ($mutationDefinitions['time_of_day']['properties']['hours']['maximum'] ?? null) !== 23
        || ($mutationDefinitions['time_of_day']['properties']['minutes']['maximum'] ?? null) !== 59
        || ($mutationDefinitions['gbp_profile']['properties']['description']['maxLength'] ?? null) !== 750
        || ($mutationDefinitions['gbp_review_reply_payload']['properties']['comment']['maxLength'] ?? null) !== 4096) {
        $errors[] = 'Measurement mutation payloads must expose exact closed and bounded GTM tag and GBP location/review shapes, including recursive-depth limits.';
    }

    $tier1 = json_decode((string) file_get_contents($root.'/openapi/tier1.json'), true, flags: JSON_THROW_ON_ERROR);
    $measurementOperations = [
        ['/v1/tenants/{tenant}/measurement-connections', 'get', 'listMeasurementConnections', null, null, '200', '../schemas/v1/measurement/connection-list.json', ['200', '401', '422', '429']],
        ['/v1/tenants/{tenant}/measurement-connections', 'post', 'createMeasurementConnection', 'application/json', '../schemas/v1/measurement/connection-create-request.json', '201', '../schemas/v1/measurement/connection-response.json', ['201', '401', '422', '429']],
        ['/v1/tenants/{tenant}/measurement-connections/{connection}', 'get', 'getMeasurementConnection', null, null, '200', '../schemas/v1/measurement/connection-response.json', ['200', '401', '404', '422', '429']],
        ['/v1/tenants/{tenant}/measurement-connections/{connection}', 'patch', 'updateMeasurementConnection', 'application/json', '../schemas/v1/measurement/connection-update-request.json', '200', '../schemas/v1/measurement/connection-response.json', ['200', '401', '404', '422', '429']],
        ['/v1/tenants/{tenant}/measurement-connections/{connection}', 'delete', 'deleteMeasurementConnection', null, null, '204', null, ['204', '401', '404', '422', '429']],
        ['/v1/tenants/{tenant}/measurement-connections/{connection}/verify', 'post', 'verifyMeasurementConnection', null, null, '200', '../schemas/v1/measurement/verification-response.json', ['200', '401', '404', '409', '422', '429', '502', '503']],
        ['/v1/tenants/{tenant}/measurement-connections/{connection}/ga4/reports', 'post', 'runGa4MeasurementReport', 'application/json', '../schemas/v1/measurement/ga4-report-request.json', '200', '../schemas/v1/measurement/report-response.json', ['200', '401', '404', '409', '422', '429', '502', '503']],
        ['/v1/tenants/{tenant}/measurement-connections/{connection}/gsc/reports', 'post', 'runGscMeasurementReport', 'application/json', '../schemas/v1/measurement/gsc-report-request.json', '200', '../schemas/v1/measurement/report-response.json', ['200', '401', '404', '409', '422', '429', '502', '503']],
        ['/v1/tenants/{tenant}/measurement-connections/{connection}/gbp/performance-reports', 'post', 'runGbpMeasurementPerformanceReport', 'application/json', '../schemas/v1/measurement/gbp-performance-report-request.json', '200', '../schemas/v1/measurement/report-response.json', ['200', '401', '404', '409', '422', '429', '502', '503']],
        ['/v1/tenants/{tenant}/measurement-connections/{connection}/{provider}/resources', 'get', 'listMeasurementProviderResources', null, null, '200', '../schemas/v1/measurement/resource-list-response.json', ['200', '401', '404', '409', '422', '429', '502', '503']],
        ['/v1/tenants/{tenant}/measurement-connections/{connection}/{provider}/mutations', 'post', 'createMeasurementProviderMutation', 'application/json', '../schemas/v1/measurement/mutation-request.json', '200', '../schemas/v1/measurement/mutation-response.json', ['200', '401', '404', '409', '422', '429', '502', '503']],
    ];
    foreach ($measurementOperations as [$path, $method, $operationId, $contentType, $requestRef, $successStatus, $responseRef, $expectedStatuses]) {
        $operation = $tier1['paths'][$path][$method] ?? [];
        $actualStatuses = array_map(static fn (int|string $status): string => (string) $status, array_keys($operation['responses'] ?? []));
        if (($operation['operationId'] ?? null) !== $operationId
            || ($operation['security'] ?? null) !== [['serviceBearer' => []]]
            || $actualStatuses !== $expectedStatuses) {
            $errors[] = sprintf('Tier 1 Measurement operation %s must expose its exact ID, Bearer scope, and statuses.', $operationId);
        }
        if ($contentType !== null && ($operation['requestBody']['content'][$contentType]['schema']['$ref'] ?? null) !== $requestRef) {
            $errors[] = sprintf('Tier 1 Measurement operation %s must use only request schema %s.', $operationId, $requestRef);
        }
        if ($contentType === null && isset($operation['requestBody'])) {
            $errors[] = sprintf('Tier 1 Measurement operation %s must not declare a request body.', $operationId);
        }
        if ($responseRef !== null && ($operation['responses'][$successStatus]['content']['application/json']['schema']['$ref'] ?? null) !== $responseRef) {
            $errors[] = sprintf('Tier 1 Measurement operation %s must use success schema %s.', $operationId, $responseRef);
        }
        foreach (array_diff($expectedStatuses, [$successStatus, '429']) as $problemStatus) {
            if (($operation['responses'][$problemStatus]['$ref'] ?? null) !== '#/components/responses/MeasurementProblem') {
                $errors[] = sprintf('Tier 1 Measurement operation %s status %s must use Measurement Problem.', $operationId, $problemStatus);
            }
        }
        if (($operation['responses']['429']['$ref'] ?? null) !== '#/components/responses/MeasurementRateLimited') {
            $errors[] = sprintf('Tier 1 Measurement operation %s must expose the current Laravel throttle response.', $operationId);
        }
    }

    $measurementPaths = array_values(array_filter(array_keys($tier1['paths'] ?? []), static fn (string $path): bool => str_starts_with($path, '/v1/tenants/{tenant}/measurement-connections')));
    if ($measurementPaths !== [
        '/v1/tenants/{tenant}/measurement-connections',
        '/v1/tenants/{tenant}/measurement-connections/{connection}',
        '/v1/tenants/{tenant}/measurement-connections/{connection}/verify',
        '/v1/tenants/{tenant}/measurement-connections/{connection}/ga4/reports',
        '/v1/tenants/{tenant}/measurement-connections/{connection}/gsc/reports',
        '/v1/tenants/{tenant}/measurement-connections/{connection}/gbp/performance-reports',
        '/v1/tenants/{tenant}/measurement-connections/{connection}/{provider}/resources',
        '/v1/tenants/{tenant}/measurement-connections/{connection}/{provider}/mutations',
    ]) {
        $errors[] = 'Tier 1 Measurement must expose exactly its eleven scoped operations across eight paths.';
    }
    foreach ($measurementPaths as $measurementPath) {
        if (isset($tier1['paths'][$measurementPath]['put'])) {
            $errors[] = sprintf('Tier 1 Measurement path %s must not expose unimplemented PUT.', $measurementPath);
        }
        if (! in_array(['$ref' => '#/components/parameters/MeasurementTenant'], $tier1['paths'][$measurementPath]['parameters'] ?? [], true)) {
            $errors[] = sprintf('Tier 1 Measurement path %s must carry exact tenant scope.', $measurementPath);
        }
    }

    $mutationOperation = $tier1['paths']['/v1/tenants/{tenant}/measurement-connections/{connection}/{provider}/mutations']['post'] ?? [];
    $measurementIdempotency = $tier1['components']['parameters']['MeasurementIdempotencyKey'] ?? [];
    if (($measurementIdempotency['required'] ?? null) !== true
        || ($measurementIdempotency['schema']['minLength'] ?? null) !== 8
        || ($measurementIdempotency['schema']['maxLength'] ?? null) !== 200
        || ! in_array(['$ref' => '#/components/parameters/MeasurementIdempotencyKey'], $mutationOperation['parameters'] ?? [], true)
        || ($mutationOperation['responses']['200']['headers']['Idempotency-Replayed']['$ref'] ?? null) !== '#/components/headers/IdempotentReplayed') {
        $errors[] = 'Measurement mutation must require its exact idempotency key and expose canonical replay state.';
    }
    foreach ($measurementOperations as [$path, $method]) {
        if ($path !== '/v1/tenants/{tenant}/measurement-connections/{connection}/{provider}/mutations'
            && in_array(['$ref' => '#/components/parameters/MeasurementIdempotencyKey'], $tier1['paths'][$path][$method]['parameters'] ?? [], true)) {
            $errors[] = sprintf('Measurement non-mutation operation %s %s must not require mutation idempotency.', strtoupper($method), $path);
        }
    }

    $fixedEndpoints = [
        'token' => 'https://oauth2.googleapis.com/token',
        'ga4_data' => 'https://analyticsdata.googleapis.com/v1beta',
        'gsc' => 'https://www.googleapis.com/webmasters/v3',
        'gtm' => 'https://tagmanager.googleapis.com/tagmanager/v2',
        'gbp_account' => 'https://mybusinessaccountmanagement.googleapis.com/v1',
        'gbp_business' => 'https://mybusinessbusinessinformation.googleapis.com/v1',
        'gbp_reviews' => 'https://mybusiness.googleapis.com/v4',
        'gbp_performance' => 'https://businessprofileperformance.googleapis.com/v1',
    ];
    $verificationBoundary = $tier1['paths']['/v1/tenants/{tenant}/measurement-connections/{connection}/verify']['post']['x-magic-html-measurement-verification-boundary'] ?? [];
    if (($verificationBoundary['provider_endpoints_server_owned'] ?? null) !== true
        || ($verificationBoundary['fixed_endpoints'] ?? null) !== $fixedEndpoints
        || ($verificationBoundary['credential_values_returned'] ?? null) !== false
        || ($verificationBoundary['provider_envelopes_returned_on_error'] ?? null) !== false) {
        $errors[] = 'Measurement verification must expose exact fixed endpoints and secret-redacted failure boundaries.';
    }
    $ga4Boundary = $tier1['paths']['/v1/tenants/{tenant}/measurement-connections/{connection}/ga4/reports']['post']['x-magic-html-measurement-read-boundary'] ?? [];
    $resourceBoundary = $tier1['paths']['/v1/tenants/{tenant}/measurement-connections/{connection}/{provider}/resources']['get']['x-magic-html-measurement-read-boundary'] ?? [];
    if (($ga4Boundary['maximum_date_difference_days'] ?? null) !== 366
        || ($ga4Boundary['maximum_rows'] ?? null) !== 5000
        || ($ga4Boundary['provider_response_maximum_bytes'] ?? null) !== 10485760
        || ($resourceBoundary['maximum_page_size'] ?? null) !== 100
        || ($resourceBoundary['maximum_page_token_length'] ?? null) !== 2048
        || ($resourceBoundary['provider_endpoint_server_owned'] ?? null) !== true) {
        $errors[] = 'Measurement reads must expose exact date, row, pagination, response-size, and server-owned endpoint boundaries.';
    }
    $mutationBoundary = $mutationOperation['x-magic-html-measurement-mutation-boundary'] ?? [];
    if (($mutationBoundary['provider_endpoints_server_owned'] ?? null) !== true
        || ($mutationBoundary['confirmation_digest'] ?? null) !== 'sha256(canonical_json({provider,operation,resource,expected_version,payload}))'
        || ($mutationBoundary['expected_live_version_required'] ?? null) !== true
        || ($mutationBoundary['idempotency_scope'] ?? null) !== 'tenant_connection_key'
        || ($mutationBoundary['automatic_retry_after_dispatch'] ?? null) !== false
        || ($mutationBoundary['ambiguous_outcome_record_state'] ?? null) !== 'processing'
        || ($mutationBoundary['ambiguous_outcome_retry'] ?? null) !== '409_idempotency_in_progress_until_reconciled'
        || ($mutationBoundary['exact_completed_replay_calls_provider'] ?? null) !== false
        || ($mutationBoundary['maximum_payload_json_bytes'] ?? null) !== 65536) {
        $errors[] = 'Measurement mutation must expose exact confirmation, optimistic version, idempotency, and unknown-no-retry boundaries.';
    }
    if (($tier1['components']['responses']['MeasurementProblem']['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/measurement/problem.json'
        || ($tier1['components']['responses']['MeasurementRateLimited']['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/measurement/rate-limit-response.json') {
        $errors[] = 'Tier 1 Measurement must expose exact domain Problem and current throttle response components.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Measurement contracts: %s', $exception->getMessage());
}

$siteSourceDocuments = [
    'schemas/v1/site-source/connection-create-request.json',
    'schemas/v1/site-source/connection-list.json',
    'schemas/v1/site-source/connection-update-request.json',
    'schemas/v1/site-source/connection.json',
    'schemas/v1/site-source/fetch-job.json',
    'schemas/v1/site-source/fetch-request.json',
    'schemas/v1/site-source/problem.json',
    'schemas/v1/site-source/snapshot.json',
    'schemas/v1/site-source/verification.json',
];

foreach ($siteSourceDocuments as $relativePath) {
    if (! in_array($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath), $jsonFiles, true)) {
        $errors[] = sprintf('Missing Site Source contract document %s', $relativePath);
    }
}

try {
    $connectionCreate = json_decode((string) file_get_contents($root.'/schemas/v1/site-source/connection-create-request.json'), true, flags: JSON_THROW_ON_ERROR);
    $connectionUpdate = json_decode((string) file_get_contents($root.'/schemas/v1/site-source/connection-update-request.json'), true, flags: JSON_THROW_ON_ERROR);
    $connection = json_decode((string) file_get_contents($root.'/schemas/v1/site-source/connection.json'), true, flags: JSON_THROW_ON_ERROR);
    $fetchJob = json_decode((string) file_get_contents($root.'/schemas/v1/site-source/fetch-job.json'), true, flags: JSON_THROW_ON_ERROR);
    $snapshot = json_decode((string) file_get_contents($root.'/schemas/v1/site-source/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    $siteSourceProblem = json_decode((string) file_get_contents($root.'/schemas/v1/site-source/problem.json'), true, flags: JSON_THROW_ON_ERROR);

    foreach ([$connectionCreate, $connectionUpdate, $connection, $fetchJob, $snapshot, $siteSourceProblem] as $siteSourceSchema) {
        if (($siteSourceSchema['additionalProperties'] ?? null) !== false) {
            $errors[] = sprintf('Site Source schema %s must reject unknown root keys.', $siteSourceSchema['$id'] ?? 'unknown');
        }
    }
    if (($connectionCreate['properties']['driver']['enum'] ?? null) !== ['github', 'sftp', 'ftps', 'ftp']) {
        $errors[] = 'Site Source must expose exactly the GitHub, SFTP, FTPS, and FTP driver allowlist.';
    }
    foreach (['github_configuration', 'sftp_configuration', 'ftps_configuration', 'ftp_configuration', 'github_credentials', 'sftp_credentials', 'password_credentials'] as $closedDefinition) {
        if (($connectionCreate['$defs'][$closedDefinition]['additionalProperties'] ?? null) !== false) {
            $errors[] = sprintf('Site Source driver definition %s must be closed.', $closedDefinition);
        }
    }
    if (($connectionCreate['$defs']['sftp_configuration']['properties']['port']['const'] ?? null) !== 22
        || ($connectionCreate['$defs']['sftp_configuration']['properties']['host_key_fingerprint']['pattern'] ?? null) !== '^SHA256:[A-Za-z0-9+/]{43}$') {
        $errors[] = 'Site Source SFTP must pin port 22 and a SHA-256 host-key fingerprint.';
    }
    if (($connectionCreate['$defs']['ftps_configuration']['properties']['verify_peer']['const'] ?? null) !== true
        || ($connectionCreate['$defs']['ftps_configuration']['properties']['port']['enum'] ?? null) !== [21, 990]
        || ($connectionCreate['$defs']['ftps_configuration']['allOf'][0]['then']['properties']['port']['const'] ?? null) !== 21
        || ($connectionCreate['$defs']['ftps_configuration']['allOf'][1]['then']['properties']['port']['const'] ?? null) !== 990) {
        $errors[] = 'Site Source FTPS must require peer verification on its finite ports.';
    }
    if (($connectionCreate['$defs']['ftp_configuration']['properties']['port']['const'] ?? null) !== 21) {
        $errors[] = 'Site Source plain FTP must remain confined to its explicitly allowed port.';
    }
    foreach (['credentials', 'token', 'password', 'private_key', 'private_key_passphrase', 'username'] as $secretProperty) {
        if (array_key_exists($secretProperty, $connection['properties'] ?? [])) {
            $errors[] = sprintf('Site Source connection responses must not expose credential property %s.', $secretProperty);
        }
    }
    if (($connection['properties']['credentials_configured']['const'] ?? null) !== true) {
        $errors[] = 'Site Source responses must expose only the credential configured marker.';
    }
    if (($snapshot['properties']['files']['maxItems'] ?? null) !== 2000
        || ($snapshot['properties']['byte_size']['maximum'] ?? null) !== 52428800
        || ($snapshot['properties']['files']['items']['properties']['byte_size']['maximum'] ?? null) !== 10485760
        || ($snapshot['properties']['files']['items']['properties']['content_base64']['contentEncoding'] ?? null) !== 'base64') {
        $errors[] = 'Site Source snapshots must expose bounded resolved base64 file bytes.';
    }
    foreach (['path', 'media_type', 'byte_size', 'sha256', 'content_base64'] as $fileField) {
        if (! in_array($fileField, $snapshot['properties']['files']['items']['required'] ?? [], true)) {
            $errors[] = sprintf('Site Source snapshot files must require %s.', $fileField);
        }
    }
    if (($fetchJob['properties']['status']['enum'] ?? null) !== ['queued', 'running', 'succeeded', 'failed']) {
        $errors[] = 'Site Source fetch must use the common asynchronous job states.';
    }
    if (! in_array('connection_changed', $siteSourceProblem['properties']['type']['enum'] ?? [], true)) {
        $errors[] = 'Site Source verification races must use the closed connection_changed Problem type.';
    }

    $tier1 = json_decode((string) file_get_contents($root.'/openapi/tier1.json'), true, flags: JSON_THROW_ON_ERROR);
    $siteSourceOperations = [
        ['/v1/tenants/{tenant}/sites/{site}/source-connections', 'get', '200', '../schemas/v1/site-source/connection-list.json'],
        ['/v1/tenants/{tenant}/sites/{site}/source-connections', 'post', '201', '../schemas/v1/site-source/connection.json'],
        ['/v1/tenants/{tenant}/sites/{site}/source-connections/{connection}', 'get', '200', '../schemas/v1/site-source/connection.json'],
        ['/v1/tenants/{tenant}/sites/{site}/source-connections/{connection}', 'patch', '200', '../schemas/v1/site-source/connection.json'],
        ['/v1/tenants/{tenant}/sites/{site}/source-connections/{connection}', 'delete', '204', null],
        ['/v1/tenants/{tenant}/sites/{site}/source-connections/{connection}/verify', 'post', '200', '../schemas/v1/site-source/verification.json'],
        ['/v1/tenants/{tenant}/sites/{site}/source-connections/{connection}/fetch-jobs', 'post', '202', '../schemas/v1/site-source/fetch-job.json'],
        ['/v1/tenants/{tenant}/sites/{site}/source-connections/{connection}/fetch-jobs/{fetchJob}', 'get', '200', '../schemas/v1/site-source/fetch-job.json'],
        ['/v1/tenants/{tenant}/sites/{site}/source-connections/{connection}/fetch-jobs/{fetchJob}/snapshot', 'get', '200', '../schemas/v1/site-source/snapshot.json'],
    ];
    foreach ($siteSourceOperations as [$path, $method, $status, $responseRef]) {
        $operation = $tier1['paths'][$path][$method] ?? [];
        if ($operation === []) {
            $errors[] = sprintf('Tier 1 OpenAPI is missing Site Source operation %s %s.', strtoupper($method), $path);

            continue;
        }
        if (($operation['security'][0]['serviceBearer'] ?? null) !== []) {
            $errors[] = sprintf('Site Source operation %s %s must require service Bearer authentication.', strtoupper($method), $path);
        }
        $parameters = $tier1['paths'][$path]['parameters'] ?? [];
        foreach (['Tenant', 'Site'] as $scopeParameter) {
            if (! in_array(['$ref' => '#/components/parameters/'.$scopeParameter], $parameters, true)) {
                $errors[] = sprintf('Site Source path %s must include %s scope.', $path, $scopeParameter);
            }
        }
        if ($responseRef !== null && ($operation['responses'][$status]['content']['application/json']['schema']['$ref'] ?? null) !== $responseRef) {
            $errors[] = sprintf('Site Source operation %s %s response %s must use %s.', strtoupper($method), $path, $status, $responseRef);
        }
    }
    $siteSourceWrites = [
        ['/v1/tenants/{tenant}/sites/{site}/source-connections', 'post', '201'],
        ['/v1/tenants/{tenant}/sites/{site}/source-connections/{connection}', 'patch', '200'],
        ['/v1/tenants/{tenant}/sites/{site}/source-connections/{connection}', 'delete', '204'],
        ['/v1/tenants/{tenant}/sites/{site}/source-connections/{connection}/verify', 'post', '200'],
        ['/v1/tenants/{tenant}/sites/{site}/source-connections/{connection}/fetch-jobs', 'post', '202'],
    ];
    foreach ($siteSourceWrites as [$path, $method, $status]) {
        $operation = $tier1['paths'][$path][$method] ?? [];
        if (! in_array(['$ref' => '#/components/parameters/SiteSourceIdempotencyKey'], $operation['parameters'] ?? [], true)
            || ($operation['responses'][$status]['headers']['Idempotent-Replayed']['$ref'] ?? null) !== '#/components/headers/IdempotentReplayed') {
            $errors[] = sprintf('Site Source write %s %s must require replay-safe idempotency metadata.', strtoupper($method), $path);
        }
    }
    $sourceSecurity = $tier1['paths']['/v1/tenants/{tenant}/sites/{site}/source-connections/{connection}/fetch-jobs']['post']['x-magic-html-source-security'] ?? [];
    $expectedSourceSecurity = [
        'public_destinations_only' => true,
        'credentials_encrypted_and_redacted' => true,
        'tls_peer_or_sftp_host_key_verification' => true,
        'plain_ftp_default_enabled' => false,
        'reject_symlinks_submodules_git_metadata_secrets_and_server_executables' => true,
        'max_files' => 2000,
        'max_file_bytes' => 10485760,
        'max_total_bytes' => 52428800,
        'timeout_seconds' => 30,
    ];
    if ($sourceSecurity !== $expectedSourceSecurity) {
        $errors[] = 'Site Source fetch must expose its complete bounded remote-source security boundary.';
    }
    if (($tier1['components']['parameters']['SiteSourceIdempotencyKey']['required'] ?? null) !== true
        || ($tier1['components']['parameters']['SiteSourceIdempotencyKey']['schema']['minLength'] ?? null) !== 8
        || ($tier1['components']['parameters']['SiteSourceIdempotencyKey']['schema']['maxLength'] ?? null) !== 200
        || ($tier1['components']['responses']['SiteSourceProblem']['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/site-source/problem.json') {
        $errors[] = 'Tier 1 Site Source must expose its bounded idempotency key and closed Problem response.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Site Source contracts: %s', $exception->getMessage());
}

$documentDocuments = [
    'schemas/v1/document/artifact.json',
    'schemas/v1/document/html.json',
    'schemas/v1/document/job.json',
    'schemas/v1/document/pdf-request.json',
    'schemas/v1/document/preview-request.json',
    'schemas/v1/document/problem.json',
];

foreach ($documentDocuments as $relativePath) {
    if (! in_array($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath), $jsonFiles, true)) {
        $errors[] = sprintf('Missing document contract document %s', $relativePath);
    }
}

try {
    $tier0 = json_decode((string) file_get_contents($root.'/openapi/tier0.json'), true, flags: JSON_THROW_ON_ERROR);
    $documentPaths = [
        '/v1/document-previews',
        '/v1/document-jobs',
        '/v1/document-jobs/{job}',
        '/v1/document-jobs/{job}/content',
    ];

    foreach ($documentPaths as $documentPath) {
        if (! isset($tier0['paths'][$documentPath])) {
            $errors[] = sprintf('Tier 0 OpenAPI is missing document path %s', $documentPath);
        }
    }

    if (($tier0['components']['securitySchemes']['documentServiceBearer']['scheme'] ?? null) !== 'bearer') {
        $errors[] = 'Tier 0 Document operations must declare service Bearer authentication.';
    }

    $documentOperations = [
        ['/v1/document-previews', 'post'],
        ['/v1/document-jobs', 'post'],
        ['/v1/document-jobs/{job}', 'get'],
        ['/v1/document-jobs/{job}/content', 'get'],
    ];
    foreach ($documentOperations as [$documentPath, $method]) {
        if (($tier0['paths'][$documentPath][$method]['security'][0]['documentServiceBearer'] ?? null) !== []) {
            $errors[] = sprintf('Tier 0 Document operation %s %s must require service Bearer authentication.', strtoupper($method), $documentPath);
        }
    }

    $pdfCreateParameters = $tier0['paths']['/v1/document-jobs']['post']['parameters'] ?? [];
    if (! in_array(['$ref' => '#/components/parameters/DocumentIdempotencyKey'], $pdfCreateParameters, true)) {
        $errors[] = 'Tier 0 PDF creation must require the Document Idempotency-Key parameter.';
    }

    if (isset($tier0['paths']['/v1/document-previews']['post']['parameters'])) {
        $errors[] = 'Tier 0 synchronous document preview must not require an idempotency key.';
    }

    $previewCsp = $tier0['paths']['/v1/document-previews']['post']['responses']['200']['headers']['Content-Security-Policy']['schema']['const'] ?? null;
    if (! is_string($previewCsp) || ! str_starts_with($previewCsp, 'sandbox;')) {
        $errors[] = 'Tier 0 document preview must contractually expose a sandboxed CSP.';
    }

    if (! isset($tier0['paths']['/v1/document-jobs/{job}/content']['get']['responses']['200']['content']['application/pdf'])) {
        $errors[] = 'Tier 0 document artifact endpoint must expose application/pdf bytes.';
    }

    $externalCommunication = $tier0['paths']['/v1/document-jobs']['post']['x-magic-html-isolation']['external_communication'] ?? null;
    if (! is_string($externalCommunication) || ! str_starts_with($externalCommunication, 'blocked')) {
        $errors[] = 'Tier 0 PDF rendering must contractually block external communication.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Tier 0 document inventory: %s', $exception->getMessage());
}

foreach (['preview-request.json', 'pdf-request.json'] as $requestDocument) {
    try {
        $schema = json_decode((string) file_get_contents($root.'/schemas/v1/document/'.$requestDocument), true, flags: JSON_THROW_ON_ERROR);
        if (($schema['additionalProperties'] ?? null) !== false) {
            $errors[] = sprintf('Document request schema %s must reject unknown root keys.', $requestDocument);
        }
    } catch (JsonException $exception) {
        $errors[] = sprintf('Unable to inspect Document request schema %s: %s', $requestDocument, $exception->getMessage());
    }
}

try {
    $pdfRequest = json_decode((string) file_get_contents($root.'/schemas/v1/document/pdf-request.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($pdfRequest['properties']['options']['additionalProperties'] ?? null) !== false) {
        $errors[] = 'Document PDF options must reject unknown keys.';
    }
    if (($pdfRequest['properties']['options']['properties']['margins']['additionalProperties'] ?? null) !== false) {
        $errors[] = 'Document PDF margins must reject unknown keys.';
    }
    foreach (['format', 'landscape'] as $cssOwnedProperty) {
        if (array_key_exists('default', $pdfRequest['properties']['options']['properties'][$cssOwnedProperty] ?? [])) {
            $errors[] = sprintf('Document PDF %s must not declare a renderer default because omitted page geometry is owned by HTML CSS @page.', $cssOwnedProperty);
        }
    }
    $requestDescription = (string) ($pdfRequest['description'] ?? '');
    if (! str_contains($requestDescription, 'CSS @page') || ! str_contains($requestDescription, 'all omitted')) {
        $errors[] = 'Document PDF requests must document that HTML CSS @page owns page geometry when renderer overrides are all omitted.';
    }

    $documentJob = json_decode((string) file_get_contents($root.'/schemas/v1/document/job.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($documentJob['properties']['page_source']['enum'] ?? null) !== ['css', 'renderer']) {
        $errors[] = 'Document jobs must expose css and renderer as the only page_source values.';
    }
    if (in_array('page_source', $documentJob['required'] ?? [], true)) {
        $errors[] = 'Document job page_source must remain additive and optional for legacy v1 responses.';
    }
    if (! in_array('format', $documentJob['required'] ?? [], true)) {
        $errors[] = 'Document jobs must retain the legacy required format field for v1 compatibility.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Document PDF request schema: %s', $exception->getMessage());
}

$deployDocuments = [
    'schemas/v1/deploy/deployment-job.json',
    'schemas/v1/deploy/deployment-request.json',
    'schemas/v1/deploy/environment-create-request.json',
    'schemas/v1/deploy/environment-list.json',
    'schemas/v1/deploy/environment-resource.json',
    'schemas/v1/deploy/environment-verification-request.json',
    'schemas/v1/deploy/environment-verification.json',
    'schemas/v1/deploy/problem.json',
];

foreach ($deployDocuments as $relativePath) {
    if (! in_array($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath), $jsonFiles, true)) {
        $errors[] = sprintf('Missing deploy contract document %s', $relativePath);
    }
}

try {
    $tier3 = json_decode((string) file_get_contents($root.'/openapi/tier3.json'), true, flags: JSON_THROW_ON_ERROR);
    $staticBuilderOperations = [
        ['/v1/builds', 'post'],
        ['/v1/builds/{build}', 'get'],
        ['/v1/builds/{build}/manifest', 'get'],
        ['/v1/builds/{build}/files/{path}', 'get'],
    ];
    foreach ($staticBuilderOperations as [$buildPath, $method]) {
        if (! isset($tier3['paths'][$buildPath][$method])) {
            $errors[] = sprintf('Tier 3 OpenAPI is missing Static Builder operation %s %s', strtoupper($method), $buildPath);
        }
    }

    foreach (array_keys($tier3['paths'] ?? []) as $tier3Path) {
        if (str_starts_with($tier3Path, '/api/')) {
            $errors[] = sprintf('Tier 3 OpenAPI path %s must be relative to the common service /api base, like Tier 0-2 paths.', $tier3Path);
        }
    }

    $deployOperations = [
        ['/v1/tenants/{tenant}/sites/{site}/environments', 'get'],
        ['/v1/tenants/{tenant}/sites/{site}/environments', 'post'],
        ['/v1/tenants/{tenant}/sites/{site}/environments/{environment}', 'get'],
        ['/v1/tenants/{tenant}/sites/{site}/environments/{environment}/verify', 'post'],
        ['/v1/tenants/{tenant}/sites/{site}/environments/{environment}/deployments', 'post'],
        ['/v1/tenants/{tenant}/sites/{site}/deployments/{deployment}', 'get'],
    ];

    foreach ($deployOperations as [$deployPath, $method]) {
        if (! isset($tier3['paths'][$deployPath][$method])) {
            $errors[] = sprintf('Tier 3 OpenAPI is missing Deploy operation %s %s', strtoupper($method), $deployPath);

            continue;
        }

        if (($tier3['paths'][$deployPath][$method]['security'][0]['deployServiceBearer'] ?? null) !== []) {
            $errors[] = sprintf('Tier 3 Deploy operation %s %s must require service Bearer authentication.', strtoupper($method), $deployPath);
        }
        if (($tier3['paths'][$deployPath][$method]['responses']['429']['$ref'] ?? null) !== '#/components/responses/DeployRateLimitProblem') {
            $errors[] = sprintf('Tier 3 Deploy operation %s %s must expose the shared 429 rate limit Problem.', strtoupper($method), $deployPath);
        }
    }

    $deploymentGetPath = '/v1/tenants/{tenant}/sites/{site}/deployments/{deployment}';
    $deploymentGetParameters = $tier3['paths'][$deploymentGetPath]['parameters'] ?? [];
    if (in_array(['$ref' => '#/components/parameters/Environment'], $deploymentGetParameters, true)) {
        $errors[] = 'Tier 3 deployment retrieval must be scoped directly by tenant and site, without an environment path parameter.';
    }
    if (isset($tier3['paths']['/v1/tenants/{tenant}/sites/{site}/environments/{environment}/deployments/{deployment}'])) {
        $errors[] = 'Tier 3 deployment retrieval must not use the obsolete environment-nested path.';
    }

    if (($tier3['components']['securitySchemes']['deployServiceBearer']['scheme'] ?? null) !== 'bearer') {
        $errors[] = 'Tier 3 Deploy operations must declare service Bearer authentication.';
    }

    $environmentCreateRef = $tier3['paths']['/v1/tenants/{tenant}/sites/{site}/environments']['post']['requestBody']['content']['application/json']['schema']['$ref'] ?? null;
    if ($environmentCreateRef !== '../schemas/v1/deploy/environment-create-request.json') {
        $errors[] = 'Tier 3 environment creation must use the closed Deploy environment request schema.';
    }

    $idempotentDeployOperations = [
        '/v1/tenants/{tenant}/sites/{site}/environments',
        '/v1/tenants/{tenant}/sites/{site}/environments/{environment}/verify',
        '/v1/tenants/{tenant}/sites/{site}/environments/{environment}/deployments',
    ];
    foreach ($idempotentDeployOperations as $deployPath) {
        $parameters = $tier3['paths'][$deployPath]['post']['parameters'] ?? [];
        if (! in_array(['$ref' => '#/components/parameters/DeployIdempotencyKey'], $parameters, true)) {
            $errors[] = sprintf('Tier 3 Deploy write POST %s must require the Deploy Idempotency-Key parameter.', $deployPath);
        }
    }

    $idempotentDeployResponses = [
        ['/v1/tenants/{tenant}/sites/{site}/environments', '201'],
        ['/v1/tenants/{tenant}/sites/{site}/environments/{environment}/verify', '200'],
        ['/v1/tenants/{tenant}/sites/{site}/environments/{environment}/deployments', '202'],
    ];
    foreach ($idempotentDeployResponses as [$deployPath, $status]) {
        $headers = $tier3['paths'][$deployPath]['post']['responses'][$status]['headers'] ?? [];
        if (($headers['Idempotent-Replayed']['$ref'] ?? null) !== '#/components/headers/DeployIdempotentReplayed') {
            $errors[] = sprintf('Tier 3 Deploy write POST %s must expose the canonical Idempotent-Replayed response header.', $deployPath);
        }
        if (isset($headers['Idempotency-Replayed'])) {
            $errors[] = sprintf('Tier 3 Deploy write POST %s must not expose the non-canonical Idempotency-Replayed response header.', $deployPath);
        }
    }
    if (! isset($tier3['components']['headers']['DeployIdempotentReplayed']) || isset($tier3['components']['headers']['DeployIdempotencyReplayed'])) {
        $errors[] = 'Tier 3 Deploy components must define only the canonical DeployIdempotentReplayed response header.';
    }
    $rateLimitResponse = $tier3['components']['responses']['DeployRateLimitProblem'] ?? [];
    if (($rateLimitResponse['headers']['Retry-After']['$ref'] ?? null) !== '#/components/headers/DeployRetryAfter') {
        $errors[] = 'Tier 3 Deploy rate limit responses must expose Retry-After.';
    }
    if (($rateLimitResponse['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/deploy/problem.json') {
        $errors[] = 'Tier 3 Deploy rate limit responses must use the Deploy Problem schema.';
    }

    $verificationPath = '/v1/tenants/{tenant}/sites/{site}/environments/{environment}/verify';
    $verificationRequestBody = $tier3['paths'][$verificationPath]['post']['requestBody'] ?? [];
    if (($verificationRequestBody['required'] ?? null) !== true) {
        $errors[] = 'Tier 3 environment verification must require its versioned JSON request body.';
    }
    $verificationRequestRef = $verificationRequestBody['content']['application/json']['schema']['$ref'] ?? null;
    if ($verificationRequestRef !== '../schemas/v1/deploy/environment-verification-request.json') {
        $errors[] = 'Tier 3 environment verification must use the closed version-only JSON request schema.';
    }

    $deploymentPath = '/v1/tenants/{tenant}/sites/{site}/environments/{environment}/deployments';
    $deploymentRequestRef = $tier3['paths'][$deploymentPath]['post']['requestBody']['content']['application/json']['schema']['$ref'] ?? null;
    if ($deploymentRequestRef !== '../schemas/v1/deploy/deployment-request.json') {
        $errors[] = 'Tier 3 deployment creation must use the closed Deploy request schema.';
    }
    $boundary = $tier3['paths'][$deploymentPath]['post']['x-magic-html-deployment-boundary'] ?? null;
    $requiredBoundary = [
        'static_builder_api' => 'service_configuration',
        'approval_api' => 'service_configuration',
        'caller_supplied_urls' => false,
        'requires_succeeded_build' => true,
        'requires_matching_artifact_digest' => true,
        'approval_artifact_type' => 'static_build',
        'requires_same_tenant_site_scope' => true,
        'requires_final_approved_decision' => true,
        'requires_matching_approval_artifact' => true,
        'requires_valid_approval_integrity' => true,
    ];
    if ($boundary !== $requiredBoundary) {
        $errors[] = 'Tier 3 deployment creation must expose the fixed Static Builder and Approval revalidation boundary.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Tier 3 Deploy inventory: %s', $exception->getMessage());
}

foreach (['environment-create-request.json', 'environment-verification-request.json', 'deployment-request.json'] as $requestDocument) {
    try {
        $schema = json_decode((string) file_get_contents($root.'/schemas/v1/deploy/'.$requestDocument), true, flags: JSON_THROW_ON_ERROR);
        if (($schema['additionalProperties'] ?? null) !== false) {
            $errors[] = sprintf('Deploy request schema %s must reject unknown root keys.', $requestDocument);
        }
    } catch (JsonException $exception) {
        $errors[] = sprintf('Unable to inspect Deploy request schema %s: %s', $requestDocument, $exception->getMessage());
    }
}

try {
    $deployProblem = json_decode((string) file_get_contents($root.'/schemas/v1/deploy/problem.json'), true, flags: JSON_THROW_ON_ERROR);
    if (! in_array('rate_limited', $deployProblem['properties']['type']['enum'] ?? [], true)) {
        $errors[] = 'Deploy Problem types must include rate_limited.';
    }
    if (! in_array(429, $deployProblem['properties']['status']['enum'] ?? [], true)) {
        $errors[] = 'Deploy Problem statuses must include 429.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Deploy Problem schema: %s', $exception->getMessage());
}

$rootPathSchemas = [];
$driverContracts = [];

try {
    $environmentRequest = json_decode((string) file_get_contents($root.'/schemas/v1/deploy/environment-create-request.json'), true, flags: JSON_THROW_ON_ERROR);
    $expectedDrivers = ['github', 'cloudflare_pages', 'sftp', 'ftps'];
    if (($environmentRequest['properties']['driver']['enum'] ?? null) !== $expectedDrivers) {
        $errors[] = 'Deploy environments must support exactly github, cloudflare_pages, sftp, and ftps drivers.';
    }
    if (($environmentRequest['properties']['tier']['enum'] ?? null) !== ['preview', 'staging', 'production']) {
        $errors[] = 'Deploy environments must classify tier as preview, staging, or production.';
    }
    if (! in_array('tier', $environmentRequest['required'] ?? [], true)) {
        $errors[] = 'Deploy environment creation must require tier.';
    }
    if (count($environmentRequest['allOf'] ?? []) !== count($expectedDrivers)) {
        $errors[] = 'Every Deploy driver must have a closed configuration and credentials schema.';
    }

    foreach ($environmentRequest['allOf'] ?? [] as $driverContract) {
        foreach (['configuration', 'credentials'] as $objectName) {
            if (($driverContract['then']['properties'][$objectName]['additionalProperties'] ?? null) !== false) {
                $errors[] = sprintf('Every Deploy driver %s schema must reject unknown keys.', $objectName);
            }
        }
    }

    foreach ($environmentRequest['allOf'] ?? [] as $driverContract) {
        $driver = $driverContract['if']['properties']['driver']['const'] ?? null;
        $rootPathSchema = $driverContract['then']['properties']['configuration']['properties']['root_path'] ?? null;
        if (is_string($driver)) {
            $driverContracts[$driver] = $driverContract;
            if (is_array($rootPathSchema)) {
                $rootPathSchemas[$driver] = $rootPathSchema;
            }
        }
    }

    $rootPathFixtures = [
        'github' => [
            'accepted' => ['public', 'sites/customer-a'],
            'rejected' => ['', '/', '.', '..', '../public', 'public/../source', 'public//assets', 'public\\assets'],
        ],
        'sftp' => [
            'accepted' => ['/public', '/sites/customer-a', '/'.str_repeat('a', 1699)],
            'rejected' => ['', '/', '//public', '/.', '/..', '/../public', '/public/../source', '/public\\assets', "/public\tassets", "/public\x7Fassets", '/'.str_repeat('a', 1700)],
        ],
        'ftps' => [
            'accepted' => ['/public', '/sites/customer-a', '/'.str_repeat('a', 1699)],
            'rejected' => ['', '/', '//public', '/.', '/..', '/../public', '/public/../source', '/public\\assets', "/public\tassets", "/public\x7Fassets", '/'.str_repeat('a', 1700)],
        ],
    ];

    $matchesStringSchema = static function (string $value, array $schema): bool {
        $length = strlen($value);
        if ($length < ($schema['minLength'] ?? 0) || $length > ($schema['maxLength'] ?? PHP_INT_MAX)) {
            return false;
        }

        return isset($schema['pattern']) && preg_match('~'.$schema['pattern'].'~D', $value) === 1;
    };

    foreach ($rootPathFixtures as $driver => $fixtures) {
        $rootPathSchema = $rootPathSchemas[$driver] ?? null;
        if (! is_array($rootPathSchema)) {
            $errors[] = sprintf('Deploy %s configuration must define a root_path schema.', $driver);

            continue;
        }
        foreach ($fixtures['accepted'] as $path) {
            if (! $matchesStringSchema($path, $rootPathSchema)) {
                $errors[] = sprintf('Deploy %s root_path must accept managed subtree fixture %s.', $driver, json_encode($path));
            }
        }
        foreach ($fixtures['rejected'] as $path) {
            if ($matchesStringSchema($path, $rootPathSchema)) {
                $errors[] = sprintf('Deploy %s root_path must reject unsafe root fixture %s.', $driver, json_encode($path));
            }
        }
    }

    $ftpsUsernameSchema = $driverContracts['ftps']['then']['properties']['credentials']['properties']['username'] ?? [];
    foreach (['deploy-user', 'deploy@example.com'] as $username) {
        if (! $matchesStringSchema($username, $ftpsUsernameSchema)) {
            $errors[] = sprintf('Deploy FTPS username must accept safe fixture %s.', json_encode($username));
        }
    }
    foreach (['deploy:user', "deploy\tuser", "deploy\nuser", "deploy\x7Fuser"] as $username) {
        if ($matchesStringSchema($username, $ftpsUsernameSchema)) {
            $errors[] = sprintf('Deploy FTPS username must reject libcurl-unsafe fixture %s.', json_encode($username));
        }
    }

    $allowsEnvironmentTier = static function (string $driver, string $tier, array $contracts): bool {
        $forbiddenTier = $contracts[$driver]['then']['not']['properties']['tier']['const'] ?? null;

        return $forbiddenTier !== $tier;
    };
    $tierFixtures = [
        'accepted' => [
            ['github', 'preview'],
            ['github', 'staging'],
            ['github', 'production'],
            ['cloudflare_pages', 'preview'],
            ['cloudflare_pages', 'staging'],
            ['cloudflare_pages', 'production'],
            ['sftp', 'preview'],
            ['sftp', 'staging'],
            ['ftps', 'preview'],
            ['ftps', 'staging'],
        ],
        'rejected' => [
            ['sftp', 'production'],
            ['ftps', 'production'],
        ],
    ];
    foreach ($tierFixtures['accepted'] as [$driver, $tier]) {
        if (! $allowsEnvironmentTier($driver, $tier, $driverContracts)) {
            $errors[] = sprintf('Deploy environment contract must accept %s tier for %s.', $tier, $driver);
        }
    }
    foreach ($tierFixtures['rejected'] as [$driver, $tier]) {
        if ($allowsEnvironmentTier($driver, $tier, $driverContracts)) {
            $errors[] = sprintf('Deploy environment contract must reject %s tier for %s.', $tier, $driver);
        }
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Deploy environment request schema: %s', $exception->getMessage());
}

try {
    $environmentResource = json_decode((string) file_get_contents($root.'/schemas/v1/deploy/environment-resource.json'), true, flags: JSON_THROW_ON_ERROR);
    if (isset($environmentResource['properties']['credentials'])) {
        $errors[] = 'Deploy environment resources must never expose credential values.';
    }
    if (($environmentResource['properties']['credentials_configured']['const'] ?? null) !== true) {
        $errors[] = 'Deploy environment resources must expose only a non-secret credential presence indicator.';
    }
    if (($environmentResource['properties']['tier']['enum'] ?? null) !== ['preview', 'staging', 'production'] || ! in_array('tier', $environmentResource['required'] ?? [], true)) {
        $errors[] = 'Deploy environment resources must expose a required preview, staging, or production tier.';
    }
    $resourceRootPathSchemas = [];
    $resourceDriverContracts = [];
    foreach ($environmentResource['allOf'] ?? [] as $driverContract) {
        $driver = $driverContract['if']['properties']['driver']['const'] ?? null;
        $rootPathSchema = $driverContract['then']['properties']['configuration']['properties']['root_path'] ?? null;
        if (is_string($driver)) {
            $resourceDriverContracts[$driver] = $driverContract;
            if (is_array($rootPathSchema)) {
                $resourceRootPathSchemas[$driver] = $rootPathSchema;
            }
        }
    }
    foreach (['github', 'sftp', 'ftps'] as $driver) {
        if (($resourceRootPathSchemas[$driver] ?? null) !== ($rootPathSchemas[$driver] ?? null)) {
            $errors[] = sprintf('Deploy %s environment resources must preserve the request root_path safety constraints.', $driver);
        }
    }
    foreach (['sftp', 'ftps'] as $driver) {
        $requestTierBoundary = $driverContracts[$driver]['then']['not'] ?? null;
        $resourceTierBoundary = $resourceDriverContracts[$driver]['then']['not'] ?? null;
        if ($resourceTierBoundary !== $requestTierBoundary) {
            $errors[] = sprintf('Deploy %s environment resources must preserve the request tier safety boundary.', $driver);
        }
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Deploy environment resource schema: %s', $exception->getMessage());
}

try {
    $deploymentRequest = json_decode((string) file_get_contents($root.'/schemas/v1/deploy/deployment-request.json'), true, flags: JSON_THROW_ON_ERROR);
    $required = $deploymentRequest['required'] ?? [];
    sort($required);
    $expectedRequired = ['approval_request_id', 'artifact_digest', 'build_id', 'contract_version'];
    if ($required !== $expectedRequired) {
        $errors[] = 'Deploy creation must require only contract_version, build_id, artifact_digest, and approval_request_id.';
    }
    if (($deploymentRequest['properties']['build_id']['format'] ?? null) !== 'uuid') {
        $errors[] = 'Deploy build_id must be a UUID.';
    }
    if (($deploymentRequest['properties']['approval_request_id']['format'] ?? null) !== 'uuid') {
        $errors[] = 'Deploy approval_request_id must be a UUID.';
    }
    if (($deploymentRequest['properties']['artifact_digest']['pattern'] ?? null) !== '^[a-f0-9]{64}$') {
        $errors[] = 'Deploy artifact_digest must be a lowercase SHA-256 digest.';
    }

    $properties = array_keys($deploymentRequest['properties'] ?? []);
    sort($properties);
    if ($properties !== $expectedRequired) {
        $errors[] = 'Deploy creation must not accept fields beyond contract_version, build_id, artifact_digest, and approval_request_id.';
    }

    foreach ($properties as $property) {
        if (str_contains(strtolower((string) $property), 'url')) {
            $errors[] = sprintf('Deploy creation must not accept caller-supplied URL property %s.', $property);
        }
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Deploy deployment request schema: %s', $exception->getMessage());
}

try {
    $deploymentJob = json_decode((string) file_get_contents($root.'/schemas/v1/deploy/deployment-job.json'), true, flags: JSON_THROW_ON_ERROR);
    $resultSchema = $deploymentJob['properties']['result']['anyOf'][1] ?? [];
    foreach (['files_count', 'bytes_count'] as $countProperty) {
        if (! in_array($countProperty, $resultSchema['required'] ?? [], true)) {
            $errors[] = sprintf('Successful Deploy results must expose %s.', $countProperty);
        }
    }
    if (isset($resultSchema['properties']['log'])) {
        $errors[] = 'Deploy provider logs must not be exposed by the deployment job contract.';
    }
    if (($deploymentJob['properties']['status']['enum'] ?? null) !== ['queued', 'running', 'succeeded', 'failed']) {
        $errors[] = 'Deploy jobs must use the normalized asynchronous job states.';
    }
    $jobErrorCodes = $deploymentJob['properties']['error']['anyOf'][1]['properties']['code']['enum'] ?? [];
    foreach (['build_not_found', 'approval_not_found'] as $errorCode) {
        if (! in_array($errorCode, $jobErrorCodes, true)) {
            $errors[] = sprintf('Deploy job errors must represent asynchronous revalidation result %s.', $errorCode);
        }
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Deploy deployment job schema: %s', $exception->getMessage());
}

try {
    $verificationRequest = json_decode((string) file_get_contents($root.'/schemas/v1/deploy/environment-verification-request.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($verificationRequest['required'] ?? null) !== ['contract_version'] || array_keys($verificationRequest['properties'] ?? []) !== ['contract_version']) {
        $errors[] = 'Deploy verification requests must accept only contract_version.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Deploy environment verification request schema: %s', $exception->getMessage());
}

try {
    $verification = json_decode((string) file_get_contents($root.'/schemas/v1/deploy/environment-verification.json'), true, flags: JSON_THROW_ON_ERROR);
    if (! in_array('checked_at', $verification['required'] ?? [], true) || isset($verification['properties']['verified_at'])) {
        $errors[] = 'Deploy verification responses must expose checked_at for both successful and failed checks.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Deploy environment verification schema: %s', $exception->getMessage());
}

try {
    $tier2 = json_decode((string) file_get_contents($root.'/openapi/tier2.json'), true, flags: JSON_THROW_ON_ERROR);
    $emailBuild = $tier2['paths']['/v1/email-builds']['post'] ?? [];
    if (($tier2['info']['version'] ?? null) !== '1.3.0') {
        $errors[] = 'Tier 2 OpenAPI version must advance for the stateless Email Builder contract.';
    }
    foreach ($emailBuild['parameters'] ?? [] as $parameter) {
        if (($parameter['name'] ?? null) === 'Idempotency-Key') {
            $errors[] = 'Synchronous side-effect-free Email Builder must not require an Idempotency-Key.';
        }
    }
    if (($emailBuild['requestBody']['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/email-builder/request.json') {
        $errors[] = 'Email Builder must use its closed request schema.';
    }
    if (($emailBuild['responses']['200']['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/email-builder/result.json') {
        $errors[] = 'Email Builder must expose its deterministic result schema.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Tier 2 Email Builder inventory: %s', $exception->getMessage());
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL);
    exit(1);
}

printf("Validated %d contract documents.\n", count($jsonFiles));
