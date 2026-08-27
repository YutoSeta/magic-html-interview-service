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
            $target = realpath(dirname($path).DIRECTORY_SEPARATOR.$value['$ref']);
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
    if (($tier1['info']['version'] ?? null) !== '1.10.0') {
        $errors[] = 'Tier 1 OpenAPI version must include replay-safe Interview writes and the tenant-scoped Knowledge inventory.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Tier 1 Site Edit target inventory: %s', $exception->getMessage());
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
