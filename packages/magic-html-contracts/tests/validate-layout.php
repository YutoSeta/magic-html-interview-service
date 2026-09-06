<?php

declare(strict_types=1);

/** @var string $root */
/** @var list<string> $errors */

try {
    $layoutLoad = static fn (string $relativePath): array => json_decode(
        (string) file_get_contents($root.'/'.$relativePath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $snapshotSchema = $layoutLoad('schemas/v1/layout/snapshot.json');
    $referenceSchema = $layoutLoad('schemas/v1/layout/reference.json');
    $validationSchema = $layoutLoad('schemas/v1/layout/validation-result.json');
    $deltaSchema = $layoutLoad('schemas/v1/layout/delta.json');
    $createSnapshotRequestSchema = $layoutLoad('schemas/v1/layout/create-snapshot-request.json');
    $createSnapshotResultSchema = $layoutLoad('schemas/v1/layout/create-snapshot-result.json');
    $freezeSnapshotRequestSchema = $layoutLoad('schemas/v1/layout/freeze-snapshot-request.json');
    $freezeSnapshotResultSchema = $layoutLoad('schemas/v1/layout/freeze-snapshot-result.json');
    $patchSnapshotRequestSchema = $layoutLoad('schemas/v1/layout/patch-snapshot-request.json');
    $patchSnapshotResultSchema = $layoutLoad('schemas/v1/layout/patch-snapshot-result.json');
    $previewValidationRequestSchema = $layoutLoad('schemas/v1/preview/layout-validation-request.json');
    $previewValidationResultSchema = $layoutLoad('schemas/v1/preview/layout-validation-result.json');
    $presentationRequestSchema = $layoutLoad('schemas/v1/wireframe/presentation-request.json');
    $presentationResultSchema = $layoutLoad('schemas/v1/wireframe/presentation-result.json');
    $frozenSnapshot = $layoutLoad('tests/fixtures/layout/snapshot-frozen.json');
    $candidateSnapshot = $layoutLoad('tests/fixtures/layout/snapshot-candidate.json');
    $referenceFixture = $layoutLoad('tests/fixtures/layout/reference.json');
    $validationFixture = $layoutLoad('tests/fixtures/layout/validation-passed.json');
    $deltaFixture = $layoutLoad('tests/fixtures/layout/delta.json');
    $finitePatchDeltaFixture = $layoutLoad('tests/fixtures/layout/delta-finite-patch.json');
    $layoutPatchPlanFixture = $layoutLoad('tests/fixtures/visual-feedback/patch-plan.json');

    $layoutClosed = static function (array $value, array $expectedKeys): bool {
        $actualKeys = array_keys($value);
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);

        return $actualKeys === $expectedKeys;
    };
    $layoutDigestMatches = static fn (mixed $value): bool => is_string($value)
        && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    $layoutSnapshotIdMatches = static fn (mixed $value): bool => is_string($value)
        && preg_match('/^ls_[a-f0-9]{64}$/', $value) === 1;
    $layoutPageKeyMatches = static fn (mixed $value): bool => is_string($value)
        && preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $value) === 1;
    $layoutCanonicalize = static function (mixed $value) use (&$layoutCanonicalize): mixed {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($layoutCanonicalize, $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) {
            $value[$key] = $layoutCanonicalize($child);
        }

        return $value;
    };
    $layoutCanonicalJson = static fn (mixed $value): string => json_encode(
        $layoutCanonicalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
    );
    $layoutCanonicalDigest = static fn (mixed $value): string => hash('sha256', $layoutCanonicalJson($value));

    $expectedSnapshotKeys = [
        'contract_version', 'profile', 'snapshot_id', 'snapshot_digest', 'status',
        'source_structure_digest', 'layout_ast_version', 'constraints', 'pages', 'validation', 'lineage',
    ];
    $snapshotRequired = $snapshotSchema['required'] ?? [];
    sort($snapshotRequired, SORT_STRING);
    $sortedExpectedSnapshotKeys = $expectedSnapshotKeys;
    sort($sortedExpectedSnapshotKeys, SORT_STRING);
    $expectedPageKeys = ['page_key', 'route', 'path', 'title', 'source_html', 'layout'];
    $expectedReferenceKeys = [
        'profile', 'snapshot_id', 'snapshot_digest', 'page_key',
        'source_html_digest', 'layout_ast_digest', 'layout_css_digest',
    ];
    $referenceRequired = $referenceSchema['required'] ?? [];
    sort($referenceRequired, SORT_STRING);
    $sortedExpectedReferenceKeys = $expectedReferenceKeys;
    sort($sortedExpectedReferenceKeys, SORT_STRING);
    $constraintSchema = $snapshotSchema['$defs']['constraints'] ?? [];
    $viewportSchema = $validationSchema['$defs']['pageResult']['properties']['viewports'] ?? [];
    $viewportPrefixes = $viewportSchema['prefixItems'] ?? [];
    $expectedViewportIdentity = [[390, 'compact'], [768, 'medium'], [1440, 'wide']];
    $actualViewportIdentity = [];
    foreach ($viewportPrefixes as $viewportPrefix) {
        $identity = $viewportPrefix['allOf'][1]['properties'] ?? [];
        $actualViewportIdentity[] = [
            $identity['width_px']['const'] ?? null,
            $identity['breakpoint']['const'] ?? null,
        ];
    }
    $requiredViewportConstants = array_map(
        static fn (array $contains): mixed => $contains['contains']['const'] ?? null,
        $constraintSchema['properties']['validation_viewports_px']['allOf'] ?? [],
    );
    $deltaOperationSchema = $deltaSchema['$defs']['operation'] ?? [];
    $expectedDeltaOperations = ['add_page', 'remove_page', 'replace_page_source', 'replace_page_layout', 'replace_constraints', 'attach_validation', 'apply_finite_layout_patch'];
    $finitePatchProperty = $deltaOperationSchema['properties']['finite_patch'] ?? [];
    $finitePatchPresence = $deltaOperationSchema['allOf'][0] ?? [];
    $finitePatchOperationBranch = array_values(array_filter(
        $deltaOperationSchema['oneOf'] ?? [],
        static fn (array $branch): bool => ($branch['properties']['operation']['const'] ?? null) === 'apply_finite_layout_patch',
    ))[0] ?? [];
    $deltaPatchPlanProperty = $deltaSchema['properties']['patch_plan'] ?? [];
    $deltaPatchPlanPresence = $deltaSchema['allOf'][0] ?? [];

    if (($snapshotSchema['$schema'] ?? null) !== 'https://json-schema.org/draft/2020-12/schema'
        || ($snapshotSchema['$id'] ?? null) !== 'https://contracts.magic-html.dev/v1/layout/snapshot.json'
        || ($snapshotSchema['additionalProperties'] ?? null) !== false
        || $snapshotRequired !== $sortedExpectedSnapshotKeys
        || ($snapshotSchema['properties']['contract_version']['const'] ?? null) !== '1.0'
        || ($snapshotSchema['properties']['profile']['const'] ?? null) !== 'layout-snapshot-v1'
        || ($snapshotSchema['properties']['snapshot_id']['$ref'] ?? null) !== '#/$defs/snapshotId'
        || ($snapshotSchema['properties']['snapshot_digest']['$ref'] ?? null) !== '#/$defs/digest'
        || ($snapshotSchema['properties']['status']['enum'] ?? null) !== ['candidate', 'frozen']
        || ($snapshotSchema['properties']['layout_ast_version']['const'] ?? null) !== 2
        || ($snapshotSchema['properties']['pages']['minItems'] ?? null) !== 1
        || ($snapshotSchema['properties']['pages']['maxItems'] ?? null) !== 8
        || ($snapshotSchema['properties']['validation']['$ref'] ?? null) !== 'validation-result.json'
        || ($snapshotSchema['$defs']['lineage']['properties']['delta']['$ref'] ?? null) !== 'delta.json'
        || ($snapshotSchema['allOf'][0]['then']['properties']['validation']['properties']['status']['const'] ?? null) !== 'pending'
        || ($snapshotSchema['allOf'][1]['then']['properties']['validation']['properties']['status']['const'] ?? null) !== 'passed'
        || ($snapshotSchema['allOf'][1]['then']['properties']['lineage']['properties']['delta']['properties']['operations']['maxItems'] ?? null) !== 1
        || ($snapshotSchema['allOf'][1]['then']['properties']['lineage']['properties']['delta']['properties']['operations']['prefixItems'][0]['properties']['operation']['const'] ?? null) !== 'attach_validation') {
        $errors[] = 'Layout Snapshot 1.0 must remain a closed, site-level, content-addressed candidate/frozen contract whose frozen state requires passed validation.';
    }

    $pageSchema = $snapshotSchema['$defs']['page'] ?? [];
    $pageRequired = $pageSchema['required'] ?? [];
    sort($pageRequired, SORT_STRING);
    $sortedExpectedPageKeys = $expectedPageKeys;
    sort($sortedExpectedPageKeys, SORT_STRING);
    $sourceHtmlSchema = $snapshotSchema['$defs']['sourceHtml'] ?? [];
    $layoutSchema = $snapshotSchema['$defs']['layout'] ?? [];
    if (($pageSchema['additionalProperties'] ?? null) !== false
        || $pageRequired !== $sortedExpectedPageKeys
        || ($sourceHtmlSchema['additionalProperties'] ?? null) !== false
        || ($sourceHtmlSchema['properties']['mime']['const'] ?? null) !== 'text/html; charset=UTF-8'
        || ($sourceHtmlSchema['properties']['content_base64']['contentEncoding'] ?? null) !== 'base64'
        || ($layoutSchema['additionalProperties'] ?? null) !== false
        || ($layoutSchema['properties']['profile']['const'] ?? null) !== 'geometry-layout-v2'
        || ($layoutSchema['properties']['ast']['type'] ?? null) !== 'object'
        || ($layoutSchema['properties']['ast']['properties']['version']['const'] ?? null) !== 2
        || ($layoutSchema['properties']['compiler']['const'] ?? null) !== 'yutoseta/magic-html-layout-ast'
        || ($layoutSchema['properties']['compiler_version']['const'] ?? null) !== '2.0') {
        $errors[] = 'Each Layout Snapshot page must bind exact source HTML bytes to a generic geometry-only Layout AST v2 and its compiled CSS.';
    }

    if (($constraintSchema['additionalProperties'] ?? null) !== false
        || ($constraintSchema['required'] ?? null) !== ['container_max_width_px', 'content_max_width_px', 'responsive', 'validation_viewports_px', 'minimum_action_height_px', 'mobile']
        || ($constraintSchema['properties']['validation_viewports_px']['const'] ?? null) !== [390, 768, 1440]
        || $requiredViewportConstants !== [390, 768, 1440]
        || ($constraintSchema['properties']['minimum_action_height_px']['minimum'] ?? null) !== 44
        || ($constraintSchema['properties']['mobile']['properties']['reflow']['const'] ?? null) !== 'single-column'
        || ($constraintSchema['properties']['mobile']['properties']['preserve_source_order']['const'] ?? null) !== true) {
        $errors[] = 'Layout AST v2 constraints must explicitly retain container/content widths, responsive thresholds, 390/768/1440 validation viewports, minimum action height, and mobile source order.';
    }

    if (($referenceSchema['$schema'] ?? null) !== 'https://json-schema.org/draft/2020-12/schema'
        || ($referenceSchema['additionalProperties'] ?? null) !== false
        || $referenceRequired !== $sortedExpectedReferenceKeys
        || ($referenceSchema['properties']['profile']['const'] ?? null) !== 'layout-snapshot-reference-v1') {
        $errors[] = 'Layout Snapshot references must be closed and bind snapshot, page, source HTML, AST, and CSS identities.';
    }

    if (($validationSchema['additionalProperties'] ?? null) !== false
        || ($validationSchema['properties']['status']['enum'] ?? null) !== ['pending', 'passed', 'failed']
        || ($validationSchema['properties']['pages']['maxItems'] ?? null) !== 8
        || ($validationSchema['$defs']['candidateSubject']['additionalProperties'] ?? null) !== false
        || ($validationSchema['$defs']['pageResult']['additionalProperties'] ?? null) !== false
        || ($viewportSchema['minItems'] ?? null) !== 3
        || ($viewportSchema['maxItems'] ?? null) !== 3
        || ($viewportSchema['items'] ?? null) !== false
        || $actualViewportIdentity !== $expectedViewportIdentity
        || ($validationSchema['$defs']['viewportResult']['additionalProperties'] ?? null) !== false
        || ($validationSchema['$defs']['measurements']['additionalProperties'] ?? null) !== false
        || ($validationSchema['$defs']['measurements']['properties']['overlap_count']['minimum'] ?? null) !== 0
        || count($validationSchema['$defs']['geometryChecks']['required'] ?? []) !== 10) {
        $errors[] = 'Layout validation results must be closed and report explicit breakpoint and geometry outcomes at exactly 390, 768, and 1440 CSS pixels.';
    }

    if (($deltaSchema['additionalProperties'] ?? null) !== false
        || ($deltaSchema['properties']['profile']['const'] ?? null) !== 'layout-snapshot-delta-v1'
        || ($deltaSchema['properties']['operations']['minItems'] ?? null) !== 1
        || ($deltaSchema['properties']['operations']['maxItems'] ?? null) !== 100
        || ($deltaOperationSchema['additionalProperties'] ?? null) !== false
        || ($deltaOperationSchema['properties']['operation']['enum'] ?? null) !== $expectedDeltaOperations
        || ($deltaPatchPlanProperty['allOf'][0]['$ref'] ?? null) !== '../visual-feedback/patch-plan.json'
        || ($deltaPatchPlanProperty['allOf'][1]['properties']['stage']['const'] ?? null) !== 'layout'
        || ($deltaPatchPlanProperty['allOf'][1]['properties']['issues']['minItems'] ?? null) !== 1
        || ($deltaPatchPlanPresence['then']['required'] ?? null) !== ['patch_plan']
        || ($deltaPatchPlanPresence['then']['properties']['change_id']['pattern'] ?? null) !== '^layout-patch-[a-f0-9]{64}$'
        || ($deltaPatchPlanPresence['then']['properties']['operations']['items']['properties']['operation']['const'] ?? null) !== 'apply_finite_layout_patch'
        || ($deltaPatchPlanPresence['else']['not']['required'] ?? null) !== ['patch_plan']
        || ($finitePatchProperty['allOf'][0]['$ref'] ?? null) !== '../visual-feedback/patch-plan.json#/$defs/issue'
        || ($finitePatchProperty['allOf'][1]['properties']['problem']['$ref'] ?? null) !== '../visual-feedback/patch-plan.json#/$defs/layoutProblem'
        || ($finitePatchProperty['allOf'][1]['properties']['operation']['$ref'] ?? null) !== '../visual-feedback/patch-plan.json#/$defs/layoutOperation'
        || ($finitePatchPresence['then']['required'] ?? null) !== ['finite_patch']
        || ($finitePatchPresence['else']['not']['required'] ?? null) !== ['finite_patch']
        || ($finitePatchOperationBranch['properties']['before_digest']['$ref'] ?? null) !== '#/$defs/digest'
        || ($finitePatchOperationBranch['properties']['after_digest']['$ref'] ?? null) !== '#/$defs/digest') {
        $errors[] = 'Layout Snapshot lineage must use a closed delta with at most 100 finite digest-bound operations.';
    }

    $layoutSchemaHasExactRoot = static function (array $schema, array $expectedKeys): bool {
        $properties = array_keys($schema['properties'] ?? []);
        $required = $schema['required'] ?? [];
        sort($properties, SORT_STRING);
        sort($required, SORT_STRING);
        sort($expectedKeys, SORT_STRING);

        return ($schema['additionalProperties'] ?? null) === false
            && $properties === $expectedKeys
            && $required === $expectedKeys;
    };
    if (! $layoutSchemaHasExactRoot($createSnapshotRequestSchema, ['contract_version', 'wireframe_ast', 'validation_viewports'])
        || ($createSnapshotRequestSchema['properties']['wireframe_ast']['$ref'] ?? null) !== '../wireframe/ast-v2.json'
        || ($createSnapshotRequestSchema['properties']['validation_viewports']['const'] ?? null) !== [390, 768, 1440]
        || ! $layoutSchemaHasExactRoot($createSnapshotResultSchema, ['contract_version', 'layout_snapshot', 'layout_snapshot_references', 'storage'])
        || ($createSnapshotResultSchema['properties']['layout_snapshot']['$ref'] ?? null) !== 'snapshot.json'
        || ($createSnapshotResultSchema['properties']['layout_snapshot_references']['items']['$ref'] ?? null) !== 'reference.json'
        || ($createSnapshotResultSchema['$defs']['storage']['additionalProperties'] ?? null) !== false
        || ($createSnapshotResultSchema['$defs']['storage']['properties']['write_once']['const'] ?? null) !== true
        || ($createSnapshotResultSchema['$defs']['storage']['properties']['encrypted']['const'] ?? null) !== true
        || ($createSnapshotResultSchema['$defs']['storage']['properties']['ttl_seconds']['minimum'] ?? null) !== 600
        || ($createSnapshotResultSchema['$defs']['storage']['properties']['ttl_seconds']['maximum'] ?? null) !== 2592000) {
        $errors[] = 'Layout Snapshot create/get envelopes must remain exact, use Wireframe AST v2, and disclose bounded write-once encrypted storage metadata.';
    }
    if (! $layoutSchemaHasExactRoot($freezeSnapshotRequestSchema, ['contract_version', 'candidate_snapshot_digest', 'validation'])
        || ($freezeSnapshotRequestSchema['properties']['validation']['allOf'][0]['$ref'] ?? null) !== 'validation-result.json'
        || ($freezeSnapshotRequestSchema['properties']['validation']['allOf'][1]['properties']['status']['const'] ?? null) !== 'passed'
        || ($freezeSnapshotRequestSchema['properties']['validation']['allOf'][1]['properties']['validator']['const'] ?? null) !== 'magic-html-preview-service'
        || ($freezeSnapshotResultSchema['allOf'][0]['$ref'] ?? null) !== 'create-snapshot-result.json'
        || ($freezeSnapshotResultSchema['allOf'][1]['properties']['layout_snapshot']['allOf'][1]['properties']['status']['const'] ?? null) !== 'frozen') {
        $errors[] = 'Layout Snapshot freeze must accept only candidate-bound passed Preview validation and return the shared frozen resource envelope.';
    }
    if (! $layoutSchemaHasExactRoot($patchSnapshotRequestSchema, ['contract_version', 'page_key', 'patch_plan'])
        || ($patchSnapshotRequestSchema['properties']['contract_version']['const'] ?? null) !== '1.0'
        || ($patchSnapshotRequestSchema['properties']['page_key']['$ref'] ?? null) !== 'snapshot.json#/$defs/pageKey'
        || ($patchSnapshotRequestSchema['properties']['patch_plan']['allOf'][0]['$ref'] ?? null) !== '../visual-feedback/patch-plan.json'
        || ($patchSnapshotRequestSchema['properties']['patch_plan']['allOf'][1]['properties']['stage']['const'] ?? null) !== 'layout'
        || ($patchSnapshotRequestSchema['properties']['patch_plan']['allOf'][1]['properties']['issues']['minItems'] ?? null) !== 1
        || ($patchSnapshotResultSchema['allOf'][0]['$ref'] ?? null) !== 'create-snapshot-result.json'
        || ($patchSnapshotResultSchema['allOf'][1]['properties']['layout_snapshot']['allOf'][1]['properties']['status']['const'] ?? null) !== 'candidate'
        || ($patchSnapshotResultSchema['allOf'][1]['properties']['layout_snapshot']['allOf'][1]['properties']['validation']['allOf'][0]['$ref'] ?? null) !== 'validation-result.json'
        || ($patchSnapshotResultSchema['allOf'][1]['properties']['layout_snapshot']['allOf'][1]['properties']['validation']['allOf'][1]['properties']['status']['const'] ?? null) !== 'pending') {
        $errors[] = 'Layout Snapshot patch must accept an exact non-empty Layout-only patch plan and return the shared candidate/pending resource envelope.';
    }
    $previewRequestViewports = $previewValidationRequestSchema['properties']['viewports']['prefixItems'] ?? [];
    $previewRequestWidths = array_map(
        static fn (array $viewport): mixed => match ($viewport['$ref'] ?? null) {
            '#/$defs/viewport390' => 390,
            '#/$defs/viewport768' => 768,
            '#/$defs/viewport1440' => 1440,
            default => null,
        },
        $previewRequestViewports,
    );
    $previewLayoutAst = $previewValidationRequestSchema['properties']['layout_ast'] ?? [];
    $previewRequestProperties = array_keys($previewValidationRequestSchema['properties'] ?? []);
    sort($previewRequestProperties, SORT_STRING);
    $expectedPreviewRequestProperties = ['contract_version', 'provider', 'layout_snapshot_ref', 'layout_ast', 'constraints', 'page', 'viewports'];
    sort($expectedPreviewRequestProperties, SORT_STRING);
    $previewResultProperties = array_keys($previewValidationResultSchema['properties'] ?? []);
    sort($previewResultProperties, SORT_STRING);
    $expectedPreviewResultProperties = ['contract_version', 'measurement_profile', 'validation_subject', 'page_validation', 'acceptance_evidence', 'issued_at', 'expires_at', 'measurement_digest', 'measurement_receipt'];
    sort($expectedPreviewResultProperties, SORT_STRING);
    if (($previewValidationRequestSchema['additionalProperties'] ?? null) !== false
        || $previewRequestProperties !== $expectedPreviewRequestProperties
        || ($previewValidationRequestSchema['required'] ?? null) !== ['contract_version', 'layout_snapshot_ref', 'layout_ast', 'constraints', 'page', 'viewports']
        || ($previewValidationRequestSchema['properties']['contract_version']['enum'] ?? null) !== ['1.0', '1.1', '1.2']
        || ($previewValidationRequestSchema['properties']['provider']['enum'] ?? null) !== ['local_puppeteer', 'cloudflare_browser_run_cdp']
        || ($previewValidationRequestSchema['allOf'][0]['then']['required'] ?? null) !== ['provider']
        || ($previewValidationRequestSchema['allOf'][0]['else']['not']['required'] ?? null) !== ['provider']
        || ($previewValidationRequestSchema['properties']['layout_snapshot_ref']['$ref'] ?? null) !== '../layout/reference.json'
        || ($previewLayoutAst['required'] ?? null) !== ['version', 'constraints', 'rules']
        || ($previewLayoutAst['additionalProperties'] ?? null) !== false
        || ($previewLayoutAst['properties']['version']['const'] ?? null) !== 2
        || ($previewLayoutAst['properties']['constraints']['$ref'] ?? null) !== '../layout/snapshot.json#/$defs/constraints'
        || ($previewLayoutAst['properties']['rules']['maxItems'] ?? null) !== 400
        || ! str_contains((string) ($previewLayoutAst['description'] ?? ''), 'layout_snapshot_ref.layout_ast_digest')
        || ! str_contains((string) ($previewLayoutAst['description'] ?? ''), 'yutoseta/magic-html-layout-ast')
        || ($previewValidationRequestSchema['properties']['constraints']['$ref'] ?? null) !== '../layout/snapshot.json#/$defs/constraints'
        || ($previewValidationRequestSchema['properties']['page']['additionalProperties'] ?? null) !== false
        || $previewRequestWidths !== [390, 768, 1440]
        || ($previewValidationResultSchema['additionalProperties'] ?? null) !== false
        || $previewResultProperties !== $expectedPreviewResultProperties
        || ($previewValidationResultSchema['required'] ?? null) !== ['contract_version', 'validation_subject', 'page_validation']
        || ($previewValidationResultSchema['properties']['contract_version']['enum'] ?? null) !== ['1.0', '1.1', '1.2']
        || ($previewValidationResultSchema['properties']['validation_subject']['$ref'] ?? null) !== '../layout/validation-result.json#/$defs/candidateSubject'
        || ($previewValidationResultSchema['properties']['page_validation']['$ref'] ?? null) !== '../layout/validation-result.json#/$defs/pageResult'
        || ($previewValidationResultSchema['allOf'][2]['then']['required'] ?? null) !== ['measurement_profile', 'acceptance_evidence', 'issued_at', 'expires_at', 'measurement_digest', 'measurement_receipt']
        || ($previewValidationResultSchema['allOf'][2]['then']['properties']['acceptance_evidence']['required'] ?? null) !== ['provider']
        || ($previewValidationResultSchema['$defs']['acceptanceEvidence']['properties']['standalone_approval']['const'] ?? null) !== false
        || count($previewValidationResultSchema['$defs']['coverage']['required'] ?? []) !== 16
        || count($previewValidationResultSchema['$defs']['samples']['required'] ?? []) !== 22
        || count($previewValidationResultSchema['$defs']['viewportSignals']['prefixItems'] ?? []) !== 3
        || ($previewValidationResultSchema['properties']['measurement_receipt']['maxLength'] ?? null) !== 524288) {
        $errors[] = 'Preview Layout validation must bind the exact candidate page AST and 390/768/1440 geometry, then expose explicit acceptance coverage and signed provider provenance in contract 1.2.';
    }
    if (! $layoutSchemaHasExactRoot($presentationRequestSchema, ['contract_version', 'layout_snapshot_ref'])
        || ($presentationRequestSchema['properties']['layout_snapshot_ref']['$ref'] ?? null) !== '../layout/reference.json'
        || ! $layoutSchemaHasExactRoot($presentationResultSchema, ['contract_version', 'snapshot_id', 'entry_path', 'files', 'file_manifest', 'layout_snapshot_reference', 'presentation_snapshot', 'wireframe_skin_ast', 'wireframe_decor_ast', 'telemetry'])
        || ($presentationResultSchema['properties']['snapshot_id']['$ref'] ?? null) !== '#/$defs/presentationId'
        || ($presentationResultSchema['properties']['layout_snapshot_reference']['$ref'] ?? null) !== '../layout/reference.json'
        || ! in_array('source_validation_status', $presentationResultSchema['$defs']['presentationSnapshot']['required'] ?? [], true)
        || ($presentationResultSchema['$defs']['presentationSnapshot']['properties']['source_snapshot_status']['enum'] ?? null) !== ['candidate', 'frozen']
        || ($presentationResultSchema['$defs']['presentationSnapshot']['properties']['source_validation_status']['enum'] ?? null) !== ['pending', 'passed']) {
        $errors[] = 'Wireframe presentation contracts must accept candidate or frozen page references and return the exact deterministic presentation envelope with source lifecycle status.';
    }

    $layoutConstraintsMatch = static function (mixed $constraints): bool {
        if (! is_array($constraints)
            || array_is_list($constraints)
            || array_keys($constraints) !== [
                'container_max_width_px', 'content_max_width_px', 'responsive',
                'validation_viewports_px', 'minimum_action_height_px', 'mobile',
            ]
            || ! is_int($constraints['container_max_width_px'])
            || $constraints['container_max_width_px'] < 320
            || $constraints['container_max_width_px'] > 3840
            || ! is_int($constraints['content_max_width_px'])
            || $constraints['content_max_width_px'] < 240
            || $constraints['content_max_width_px'] > $constraints['container_max_width_px']
            || ! is_array($constraints['responsive'])
            || array_keys($constraints['responsive']) !== ['compact_max_px', 'medium_min_px', 'wide_min_px']) {
            return false;
        }
        $compact = $constraints['responsive']['compact_max_px'];
        $medium = $constraints['responsive']['medium_min_px'];
        $wide = $constraints['responsive']['wide_min_px'];
        $viewports = $constraints['validation_viewports_px'];
        if ((! is_int($compact) && ! is_float($compact))
            || ! is_int($medium)
            || ! is_int($wide)
            || $compact < 390 || $compact > 767.98
            || $medium < 391 || $medium > 768
            || $wide < 769 || $wide > 1440
            || abs(((float) $compact + 0.02) - $medium) > 0.001
            || $wide <= $medium
            || ! is_array($viewports)
            || ! array_is_list($viewports)
            || count($viewports) < 3
            || count($viewports) > 10
            || count($viewports) !== count(array_unique($viewports, SORT_REGULAR))) {
            return false;
        }
        $sortedViewports = $viewports;
        sort($sortedViewports, SORT_NUMERIC);
        if ($viewports !== $sortedViewports
            || array_diff([390, 768, 1440], $viewports) !== []
            || array_filter($viewports, static fn (mixed $width): bool => ! is_int($width) || $width < 320 || $width > 3840) !== []) {
            return false;
        }

        return is_int($constraints['minimum_action_height_px'])
            && $constraints['minimum_action_height_px'] >= 44
            && $constraints['minimum_action_height_px'] <= 256
            && $constraints['mobile'] === ['reflow' => 'single-column', 'preserve_source_order' => true];
    };

    $layoutValidationMatches = static function (
        mixed $validation,
        ?array $constraints = null,
        ?array $snapshotPages = null,
    ) use ($layoutClosed, $layoutDigestMatches, $layoutPageKeyMatches, $layoutSnapshotIdMatches): bool {
        if (! is_array($validation)
            || array_is_list($validation)
            || ! $layoutClosed($validation, ['status', 'validator', 'validator_version', 'subject', 'pages'])
            || ! in_array($validation['status'], ['pending', 'passed', 'failed'], true)
            || ! is_string($validation['validator'])
            || strlen(trim($validation['validator'])) < 1
            || strlen($validation['validator']) > 100
            || ! is_string($validation['validator_version'])
            || strlen(trim($validation['validator_version'])) < 1
            || strlen($validation['validator_version']) > 50
            || ! is_array($validation['pages'])
            || ! array_is_list($validation['pages'])
            || count($validation['pages']) < 1
            || count($validation['pages']) > 8) {
            return false;
        }

        $subjectMatches = false;
        if (is_array($validation['subject'])
            && ! array_is_list($validation['subject'])
            && $layoutClosed($validation['subject'], ['candidate_snapshot_id', 'candidate_snapshot_digest'])
            && $layoutSnapshotIdMatches($validation['subject']['candidate_snapshot_id'])
            && $layoutDigestMatches($validation['subject']['candidate_snapshot_digest'])
            && $validation['subject']['candidate_snapshot_id'] === 'ls_'.$validation['subject']['candidate_snapshot_digest']) {
            $subjectMatches = true;
        }

        $expectedPages = [];
        if ($snapshotPages !== null) {
            foreach ($snapshotPages as $snapshotPage) {
                if (! is_array($snapshotPage) || ! isset($snapshotPage['page_key'], $snapshotPage['route'], $snapshotPage['path'])) {
                    return false;
                }
                $expectedPages[$snapshotPage['page_key']] = [
                    'route' => $snapshotPage['route'],
                    'path' => $snapshotPage['path'],
                ];
            }
            if (count($expectedPages) !== count($snapshotPages)
                || count($expectedPages) !== count($validation['pages'])) {
                return false;
            }
        }

        $issueCodes = [
            'breakpoint_mismatch', 'container_overflow', 'content_overflow', 'horizontal_overflow', 'action_height', 'source_order',
            'overlap', 'clipped_content', 'zero_or_extreme_dimension', 'section_spacing', 'line_length', 'grid_columns', 'column_ratio',
            'image_aspect', 'form_dimensions', 'mobile_order', 'breakpoint_discontinuity', 'anchor_collision',
        ];
        $measurementKeys = [
            'container_width_px', 'content_width_px', 'horizontal_overflow_px', 'minimum_action_height_px',
            'overlap_count', 'clipped_content_count', 'zero_dimension_count', 'responsive_violation_count',
            'image_aspect_violation_count', 'checks',
        ];
        $checkKeys = [
            'container_width_within_limit', 'content_width_within_limit', 'no_horizontal_overflow',
            'minimum_action_height_met', 'source_order_preserved', 'no_overlap', 'no_clipped_content',
            'no_zero_dimensions', 'responsive_behavior_matches', 'image_aspect_preserved',
        ];
        $viewportIdentities = [[390, 'compact'], [768, 'medium'], [1440, 'wide']];
        $seenPages = [];
        $allPending = true;
        $allPassed = true;
        $hasFailure = false;
        foreach ($validation['pages'] as $page) {
            if (! is_array($page)
                || array_is_list($page)
                || ! $layoutClosed($page, ['page_key', 'route', 'path', 'viewports'])
                || ! $layoutPageKeyMatches($page['page_key'])
                || isset($seenPages[$page['page_key']])
                || ! is_string($page['route'])
                || strlen($page['route']) > 200
                || preg_match('#^/(?:[a-z0-9][a-z0-9-]*(?:/[a-z0-9][a-z0-9-]*)*)?$#', $page['route']) !== 1
                || ! is_string($page['path'])
                || strlen($page['path']) > 200
                || preg_match('#^(?:[a-z0-9][a-z0-9-]*/)*[a-z0-9][a-z0-9-]*\\.html$#', $page['path']) !== 1
                || ! is_array($page['viewports'])
                || ! array_is_list($page['viewports'])
                || count($page['viewports']) !== 3) {
                return false;
            }
            if ($expectedPages !== []
                && (($expectedPages[$page['page_key']] ?? null) !== ['route' => $page['route'], 'path' => $page['path']])) {
                return false;
            }
            $seenPages[$page['page_key']] = true;

            foreach ($page['viewports'] as $index => $viewport) {
                if (! is_array($viewport)
                    || array_is_list($viewport)
                    || ! $layoutClosed($viewport, ['width_px', 'height_px', 'breakpoint', 'breakpoint_outcome', 'geometry_outcome', 'measurements', 'issues'])
                    || [$viewport['width_px'], $viewport['breakpoint']] !== $viewportIdentities[$index]
                    || ! is_int($viewport['height_px'])
                    || $viewport['height_px'] < 320
                    || $viewport['height_px'] > 4320
                    || ! in_array($viewport['breakpoint_outcome'], ['pending', 'passed', 'failed'], true)
                    || ! in_array($viewport['geometry_outcome'], ['pending', 'passed', 'failed'], true)
                    || ! is_array($viewport['issues'])
                    || ! array_is_list($viewport['issues'])
                    || count($viewport['issues']) > 50) {
                    return false;
                }
                foreach ($viewport['issues'] as $issue) {
                    if (! is_array($issue)
                        || ! $layoutClosed($issue, ['code', 'message', 'count'])
                        || ! in_array($issue['code'], $issueCodes, true)
                        || ! is_string($issue['message'])
                        || strlen(trim($issue['message'])) < 1
                        || strlen($issue['message']) > 500
                        || ! is_int($issue['count'])
                        || $issue['count'] < 1
                        || $issue['count'] > 10000) {
                        return false;
                    }
                }

                $isPending = $viewport['breakpoint_outcome'] === 'pending' && $viewport['geometry_outcome'] === 'pending';
                $isPassed = $viewport['breakpoint_outcome'] === 'passed' && $viewport['geometry_outcome'] === 'passed';
                $isFailed = $viewport['breakpoint_outcome'] === 'failed' || $viewport['geometry_outcome'] === 'failed';
                if (! $isPending && ! $isPassed && ! $isFailed) {
                    return false;
                }
                $allPending = $allPending && $isPending;
                $allPassed = $allPassed && $isPassed;
                $hasFailure = $hasFailure || $isFailed;

                if ($isPending) {
                    if ($viewport['measurements'] !== null || $viewport['issues'] !== []) {
                        return false;
                    }
                    continue;
                }

                $measurements = $viewport['measurements'];
                if (! is_array($measurements)
                    || array_is_list($measurements)
                    || ! $layoutClosed($measurements, $measurementKeys)
                    || ! is_int($measurements['overlap_count'])
                    || ! is_int($measurements['clipped_content_count'])
                    || ! is_int($measurements['zero_dimension_count'])
                    || ! is_int($measurements['responsive_violation_count'])
                    || ! is_int($measurements['image_aspect_violation_count'])
                    || min(
                        $measurements['overlap_count'],
                        $measurements['clipped_content_count'],
                        $measurements['zero_dimension_count'],
                        $measurements['responsive_violation_count'],
                        $measurements['image_aspect_violation_count'],
                    ) < 0
                    || max(
                        $measurements['overlap_count'],
                        $measurements['clipped_content_count'],
                        $measurements['zero_dimension_count'],
                        $measurements['responsive_violation_count'],
                        $measurements['image_aspect_violation_count'],
                    ) > 10000
                    || (! is_int($measurements['container_width_px']) && ! is_float($measurements['container_width_px']))
                    || (! is_int($measurements['content_width_px']) && ! is_float($measurements['content_width_px']))
                    || (! is_int($measurements['horizontal_overflow_px']) && ! is_float($measurements['horizontal_overflow_px']))
                    || (! is_int($measurements['minimum_action_height_px']) && ! is_float($measurements['minimum_action_height_px']))
                    || $measurements['container_width_px'] < 0
                    || $measurements['container_width_px'] > 3840
                    || $measurements['content_width_px'] < 0
                    || $measurements['content_width_px'] > 3840
                    || $measurements['horizontal_overflow_px'] < 0
                    || $measurements['horizontal_overflow_px'] > 3840
                    || $measurements['minimum_action_height_px'] < 0
                    || $measurements['minimum_action_height_px'] > 512
                    || ! is_array($measurements['checks'])
                    || array_is_list($measurements['checks'])
                    || ! $layoutClosed($measurements['checks'], $checkKeys)
                    || array_filter($measurements['checks'], static fn (mixed $check): bool => ! is_bool($check)) !== []) {
                    return false;
                }

                if ($isPassed
                    && ($viewport['issues'] !== []
                        || in_array(false, $measurements['checks'], true)
                        || $measurements['horizontal_overflow_px'] != 0
                        || $measurements['overlap_count'] !== 0
                        || $measurements['clipped_content_count'] !== 0
                        || $measurements['zero_dimension_count'] !== 0
                        || $measurements['responsive_violation_count'] !== 0
                        || $measurements['image_aspect_violation_count'] !== 0
                        || ($constraints !== null
                            && ($measurements['container_width_px'] > min($viewport['width_px'], $constraints['container_max_width_px'])
                                || $measurements['content_width_px'] > min($measurements['container_width_px'], $constraints['content_max_width_px'])
                                || $measurements['minimum_action_height_px'] < $constraints['minimum_action_height_px'])))) {
                    return false;
                }
                if ($isFailed && $viewport['issues'] === []) {
                    return false;
                }
            }
        }

        return match ($validation['status']) {
            'pending' => $validation['subject'] === null && $allPending,
            'passed' => $subjectMatches && $allPassed,
            'failed' => $subjectMatches && $hasFailure,
            default => false,
        };
    };
    $layoutPatchOperationTypes = [
        'set_cluster_wrap', 'set_flow', 'set_columns', 'set_column_ratio', 'set_gap',
        'set_section_spacing', 'set_alignment', 'set_image_aspect', 'set_object_fit',
        'set_container_max_width', 'set_content_max_width', 'set_minimum_action_height',
    ];
    $layoutPatchProblems = [
        'unintended_wrap', 'horizontal_overflow', 'overlap', 'clipped_content', 'zero_dimension',
        'container_too_wide', 'container_too_narrow', 'content_too_wide', 'content_too_narrow',
        'spacing_too_tight', 'spacing_too_loose', 'misalignment',
        'weak_hierarchy', 'column_imbalance', 'incorrect_reflow', 'incorrect_mobile_order', 'image_aspect_mismatch',
        'object_fit_mismatch', 'form_control_too_small', 'action_too_small',
    ];
    $layoutPatchTokenMatches = static fn (mixed $value): bool => is_string($value)
        && preg_match('/^(?:[a-z]|[0-9]+[a-z])[a-z0-9-]{0,99}$/', $value) === 1;
    $layoutFinitePatchMatches = static function (mixed $patch) use (
        $layoutClosed,
        $layoutPatchOperationTypes,
        $layoutPatchProblems,
        $layoutPatchTokenMatches,
    ): bool {
        if (! is_array($patch)
            || array_is_list($patch)
            || ! $layoutClosed($patch, ['target_id', 'viewport', 'problem', 'operation'])
            || ! is_string($patch['target_id'])
            || preg_match('/^(?:\$constraints|[a-z][a-z0-9._-]{0,199})$/', $patch['target_id']) !== 1
            || ! in_array($patch['problem'], $layoutPatchProblems, true)
            || ! is_array($patch['viewport'])
            || array_is_list($patch['viewport'])
            || count($patch['viewport']) < 1
            || count($patch['viewport']) > 2
            || array_diff(array_keys($patch['viewport']), ['min_width', 'max_width']) !== []
            || ! is_array($patch['operation'])
            || array_is_list($patch['operation'])
            || ! $layoutClosed($patch['operation'], ['type', 'value'])
            || ! in_array($patch['operation']['type'], $layoutPatchOperationTypes, true)) {
            return false;
        }
        foreach ($patch['viewport'] as $bound) {
            if (! is_int($bound) || $bound < 320 || $bound > 7680) {
                return false;
            }
        }
        if (isset($patch['viewport']['min_width'], $patch['viewport']['max_width'])
            && $patch['viewport']['min_width'] > $patch['viewport']['max_width']) {
            return false;
        }

        $type = $patch['operation']['type'];
        $value = $patch['operation']['value'];
        $constraintOperations = [
            'set_container_max_width', 'set_content_max_width', 'set_minimum_action_height',
        ];
        if (in_array($type, $constraintOperations, true) && $patch['viewport'] !== ['min_width' => 320]) {
            return false;
        }
        $valueMatches = match ($type) {
            'set_cluster_wrap' => in_array($value, ['wrap', 'nowrap'], true),
            'set_flow' => in_array($value, ['stack', 'cluster', 'row', 'grid', 'split', 'overlay'], true),
            'set_columns' => in_array($value, ['one', 'two', 'three', 'auto-fit'], true),
            'set_column_ratio' => is_string($value)
                && preg_match('/^(?:[0-9]*\.?[0-9]+fr)(?:\s+[0-9]*\.?[0-9]+fr){1,5}$/', $value) === 1,
            'set_gap', 'set_section_spacing', 'set_image_aspect' => $layoutPatchTokenMatches($value),
            'set_alignment' => in_array($value, ['start', 'center', 'end', 'stretch', 'baseline'], true),
            'set_object_fit' => in_array($value, ['cover', 'contain', 'fill', 'none', 'scale-down'], true),
            'set_container_max_width' => is_int($value) && $value >= 320 && $value <= 3840,
            'set_content_max_width' => is_int($value) && $value >= 240 && $value <= 3840,
            'set_minimum_action_height' => is_int($value) && $value >= 44 && $value <= 256,
            default => false,
        };

        return $valueMatches
            && (($patch['target_id'] === '$constraints') === in_array($type, $constraintOperations, true));
    };
    $layoutPatchPlanMatches = static function (mixed $plan) use (
        $layoutClosed,
        $layoutDigestMatches,
        $layoutFinitePatchMatches,
    ): bool {
        if (! is_array($plan)
            || array_is_list($plan)
            || ! $layoutClosed($plan, ['contract_version', 'stage', 'base_ast_digest', 'issues'])
            || $plan['contract_version'] !== '1.0'
            || $plan['stage'] !== 'layout'
            || ! $layoutDigestMatches($plan['base_ast_digest'])
            || ! is_array($plan['issues'])
            || ! array_is_list($plan['issues'])
            || count($plan['issues']) < 1
            || count($plan['issues']) > 20) {
            return false;
        }
        $seen = [];
        foreach ($plan['issues'] as $issue) {
            if (! $layoutFinitePatchMatches($issue)) {
                return false;
            }
            $signature = $issue['target_id'].'|'.json_encode($issue['viewport'], JSON_THROW_ON_ERROR)
                .'|'.$issue['operation']['type'];
            if (isset($seen[$signature])) {
                return false;
            }
            $seen[$signature] = true;
        }

        return true;
    };
    $layoutPatchSnapshotRequestMatches = static function (mixed $request) use (
        $layoutClosed,
        $layoutPageKeyMatches,
        $layoutPatchPlanMatches,
    ): bool {
        return is_array($request)
            && ! array_is_list($request)
            && $layoutClosed($request, ['contract_version', 'page_key', 'patch_plan'])
            && $request['contract_version'] === '1.0'
            && $layoutPageKeyMatches($request['page_key'])
            && $layoutPatchPlanMatches($request['patch_plan']);
    };
    $layoutDeltaMatches = static function (mixed $delta) use (
        $layoutCanonicalDigest,
        $layoutCanonicalJson,
        $layoutClosed,
        $layoutDigestMatches,
        $layoutFinitePatchMatches,
        $layoutPageKeyMatches,
        $layoutPatchPlanMatches,
    ): bool {
        if (! is_array($delta)
            || array_is_list($delta)
            || ! is_array($delta['operations'] ?? null)
            || ! array_is_list($delta['operations'])
            || count($delta['operations']) < 1
            || count($delta['operations']) > 100) {
            return false;
        }
        $finiteOperationCount = count(array_filter(
            $delta['operations'],
            static fn (mixed $operation): bool => is_array($operation)
                && ($operation['operation'] ?? null) === 'apply_finite_layout_patch',
        ));
        $allFinite = $finiteOperationCount === count($delta['operations']);
        if ($finiteOperationCount > 0 && ! $allFinite) {
            return false;
        }
        $expectedDeltaKeys = ['profile', 'change_id', 'reason', 'operations'];
        if ($allFinite) {
            $expectedDeltaKeys[] = 'patch_plan';
        }
        if (! $layoutClosed($delta, $expectedDeltaKeys)
            || $delta['profile'] !== 'layout-snapshot-delta-v1'
            || ! is_string($delta['change_id'])
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/', $delta['change_id']) !== 1
            || ! is_string($delta['reason'])
            || strlen($delta['reason']) < 1
            || strlen($delta['reason']) > 2000
            || ($allFinite && (! $layoutPatchPlanMatches($delta['patch_plan'])
                || count($delta['patch_plan']['issues']) !== count($delta['operations'])
                || $delta['change_id'] !== 'layout-patch-'.$layoutCanonicalDigest($delta['patch_plan'])))) {
            return false;
        }

        foreach ($delta['operations'] as $index => $operation) {
            $operationName = is_array($operation) ? ($operation['operation'] ?? null) : null;
            $expectedOperationKeys = ['sequence', 'operation', 'target', 'before_digest', 'after_digest', 'summary'];
            if ($operationName === 'apply_finite_layout_patch') {
                $expectedOperationKeys[] = 'finite_patch';
            }
            if (! is_array($operation)
                || ! $layoutClosed($operation, $expectedOperationKeys)
                || $operation['sequence'] !== $index + 1
                || ! in_array($operationName, ['add_page', 'remove_page', 'replace_page_source', 'replace_page_layout', 'replace_constraints', 'attach_validation', 'apply_finite_layout_patch'], true)
                || ! is_array($operation['target'])
                || ! $layoutClosed($operation['target'], ['kind', 'page_key'])
                || ! is_string($operation['summary'])
                || strlen($operation['summary']) < 1
                || strlen($operation['summary']) > 500) {
                return false;
            }
            $isPage = $operation['target']['kind'] === 'page' && $layoutPageKeyMatches($operation['target']['page_key']);
            $isConstraints = $operation['target'] === ['kind' => 'constraints', 'page_key' => null];
            $isSnapshot = $operation['target'] === ['kind' => 'snapshot', 'page_key' => null];
            $beforeIsDigest = $layoutDigestMatches($operation['before_digest']);
            $afterIsDigest = $layoutDigestMatches($operation['after_digest']);
            $validOperation = match ($operation['operation']) {
                'add_page' => $isPage && $operation['before_digest'] === null && $afterIsDigest,
                'remove_page' => $isPage && $beforeIsDigest && $operation['after_digest'] === null,
                'replace_page_source', 'replace_page_layout' => $isPage && $beforeIsDigest && $afterIsDigest,
                'replace_constraints' => $isConstraints && $beforeIsDigest && $afterIsDigest,
                'attach_validation' => $isSnapshot && $beforeIsDigest && $afterIsDigest,
                'apply_finite_layout_patch' => $beforeIsDigest
                    && $afterIsDigest
                    && $operation['before_digest'] !== $operation['after_digest']
                    && $layoutFinitePatchMatches($operation['finite_patch'] ?? null)
                    && (($operation['finite_patch']['target_id'] === '$constraints' && $isConstraints)
                        || ($operation['finite_patch']['target_id'] !== '$constraints' && $isPage)),
                default => false,
            };
            if (! $validOperation) {
                return false;
            }
            if ($operationName === 'apply_finite_layout_patch'
                && $layoutCanonicalJson($operation['finite_patch']) !== $layoutCanonicalJson($delta['patch_plan']['issues'][$index])) {
                return false;
            }
        }

        return true;
    };

    $layoutHasForbiddenAstKey = static function (mixed $value) use (&$layoutHasForbiddenAstKey): bool {
        if (! is_array($value)) {
            return false;
        }
        if (! array_is_list($value)) {
            foreach (array_keys($value) as $key) {
                if (in_array(strtolower((string) $key), ['surface', 'color', 'paint', 'decor', 'motion'], true)) {
                    return true;
                }
            }
        }
        foreach ($value as $child) {
            if ($layoutHasForbiddenAstKey($child)) {
                return true;
            }
        }

        return false;
    };

    $layoutSnapshotMatches = static function (mixed $snapshot) use (
        $expectedSnapshotKeys,
        $layoutCanonicalDigest,
        $layoutCanonicalJson,
        $layoutClosed,
        $layoutConstraintsMatch,
        $layoutDeltaMatches,
        $layoutDigestMatches,
        $layoutHasForbiddenAstKey,
        $layoutPageKeyMatches,
        $layoutSnapshotIdMatches,
        $layoutValidationMatches,
    ): bool {
        if (! is_array($snapshot)
            || array_is_list($snapshot)
            || ! $layoutClosed($snapshot, $expectedSnapshotKeys)
            || $snapshot['contract_version'] !== '1.0'
            || $snapshot['profile'] !== 'layout-snapshot-v1'
            || ! $layoutSnapshotIdMatches($snapshot['snapshot_id'])
            || ! $layoutDigestMatches($snapshot['snapshot_digest'])
            || $snapshot['snapshot_id'] !== 'ls_'.$snapshot['snapshot_digest']
            || ! in_array($snapshot['status'], ['candidate', 'frozen'], true)
            || ! $layoutDigestMatches($snapshot['source_structure_digest'])
            || $snapshot['layout_ast_version'] !== 2
            || ! $layoutConstraintsMatch($snapshot['constraints'])
            || ! is_array($snapshot['pages'])
            || count($snapshot['pages']) < 1
            || count($snapshot['pages']) > 8
            || ! $layoutValidationMatches($snapshot['validation'], $snapshot['constraints'], $snapshot['pages'])
            || ($snapshot['status'] === 'candidate' && $snapshot['validation']['status'] !== 'pending')
            || ($snapshot['status'] === 'frozen' && $snapshot['validation']['status'] !== 'passed')) {
            return false;
        }

        $pageKeys = [];
        $routes = [];
        $paths = [];
        foreach ($snapshot['pages'] as $page) {
            if (! is_array($page)
                || ! $layoutClosed($page, ['page_key', 'route', 'path', 'title', 'source_html', 'layout'])
                || ! $layoutPageKeyMatches($page['page_key'])
                || isset($pageKeys[$page['page_key']])
                || ! is_string($page['route'])
                || strlen($page['route']) > 200
                || preg_match('#^/(?:[a-z0-9][a-z0-9-]*(?:/[a-z0-9][a-z0-9-]*)*)?$#', $page['route']) !== 1
                || isset($routes[$page['route']])
                || ! is_string($page['path'])
                || strlen($page['path']) > 200
                || preg_match('#^(?:[a-z0-9][a-z0-9-]*/)*[a-z0-9][a-z0-9-]*\\.html$#', $page['path']) !== 1
                || isset($paths[$page['path']])
                || ! is_string($page['title'])
                || strlen($page['title']) < 1
                || strlen($page['title']) > 200
                || ! is_array($page['source_html'])
                || ! $layoutClosed($page['source_html'], ['mime', 'content_base64', 'sha256'])
                || $page['source_html']['mime'] !== 'text/html; charset=UTF-8'
                || ! is_string($page['source_html']['content_base64'])
                || ! $layoutDigestMatches($page['source_html']['sha256'])) {
                return false;
            }
            $sourceHtml = base64_decode($page['source_html']['content_base64'], true);
            if (! is_string($sourceHtml)
                || $sourceHtml === ''
                || hash('sha256', $sourceHtml) !== $page['source_html']['sha256']) {
                return false;
            }

            $layout = $page['layout'];
            if (! is_array($layout)
                || ! $layoutClosed($layout, ['profile', 'ast', 'css', 'ast_digest', 'css_digest', 'compiler', 'compiler_version'])
                || $layout['profile'] !== 'geometry-layout-v2'
                || ! is_array($layout['ast'])
                || ($layout['ast']['version'] ?? null) !== 2
                || ! isset($layout['ast']['constraints'], $layout['ast']['rules'])
                || ! is_array($layout['ast']['rules'])
                || count($layout['ast']['rules']) < 1
                || count($layout['ast']['rules']) > 400
                || $layoutCanonicalJson($layout['ast']['constraints']) !== $layoutCanonicalJson($snapshot['constraints'])
                || $layoutHasForbiddenAstKey($layout['ast'])
                || ! is_string($layout['css'])
                || strlen($layout['css']) < 1
                || strlen($layout['css']) > 2000000
                || preg_match('/(?:^|[;{])\s*(?:color|background(?:-color)?|border-color|box-shadow|filter|animation|transition)\s*:/i', $layout['css']) === 1
                || ! $layoutDigestMatches($layout['ast_digest'])
                || $layoutCanonicalDigest($layout['ast']) !== $layout['ast_digest']
                || ! $layoutDigestMatches($layout['css_digest'])
                || hash('sha256', $layout['css']) !== $layout['css_digest']
                || $layout['compiler'] !== 'yutoseta/magic-html-layout-ast'
                || $layout['compiler_version'] !== '2.0') {
                return false;
            }
            $pageKeys[$page['page_key']] = true;
            $routes[$page['route']] = true;
            $paths[$page['path']] = true;
        }

        if ($snapshot['lineage'] !== null) {
            $lineage = $snapshot['lineage'];
            if (! is_array($lineage)
                || ! $layoutClosed($lineage, ['parent_snapshot_id', 'parent_snapshot_digest', 'delta'])
                || ! $layoutSnapshotIdMatches($lineage['parent_snapshot_id'])
                || ! $layoutDigestMatches($lineage['parent_snapshot_digest'])
                || $lineage['parent_snapshot_id'] !== 'ls_'.$lineage['parent_snapshot_digest']
                || ! $layoutDeltaMatches($lineage['delta'])) {
                return false;
            }
        }
        if ($snapshot['status'] === 'frozen'
            && (! is_array($snapshot['lineage'])
                || ($snapshot['validation']['subject']['candidate_snapshot_id'] ?? null) !== $snapshot['lineage']['parent_snapshot_id']
                || ($snapshot['validation']['subject']['candidate_snapshot_digest'] ?? null) !== $snapshot['lineage']['parent_snapshot_digest']
                || count($snapshot['lineage']['delta']['operations'] ?? []) !== 1
                || ($snapshot['lineage']['delta']['operations'][0]['operation'] ?? null) !== 'attach_validation'
                || ($snapshot['lineage']['delta']['operations'][0]['target'] ?? null) !== ['kind' => 'snapshot', 'page_key' => null]
                || ($snapshot['lineage']['delta']['operations'][0]['before_digest'] ?? null) !== $snapshot['lineage']['parent_snapshot_digest']
                || ($snapshot['lineage']['delta']['operations'][0]['after_digest'] ?? null) !== $layoutCanonicalDigest($snapshot['validation']))) {
            return false;
        }

        $payload = $snapshot;
        unset($payload['snapshot_id'], $payload['snapshot_digest']);

        return $layoutCanonicalDigest($payload) === $snapshot['snapshot_digest'];
    };

    $layoutReferenceMatches = static function (mixed $reference, array $snapshot) use (
        $expectedReferenceKeys,
        $layoutClosed,
        $layoutDigestMatches,
        $layoutPageKeyMatches,
        $layoutSnapshotIdMatches,
    ): bool {
        if (! is_array($reference)
            || ! $layoutClosed($reference, $expectedReferenceKeys)
            || $reference['profile'] !== 'layout-snapshot-reference-v1'
            || ! $layoutSnapshotIdMatches($reference['snapshot_id'])
            || ! $layoutDigestMatches($reference['snapshot_digest'])
            || $reference['snapshot_id'] !== 'ls_'.$reference['snapshot_digest']
            || $reference['snapshot_id'] !== $snapshot['snapshot_id']
            || $reference['snapshot_digest'] !== $snapshot['snapshot_digest']
            || ! $layoutPageKeyMatches($reference['page_key'])) {
            return false;
        }
        $pages = array_values(array_filter(
            $snapshot['pages'],
            static fn (array $page): bool => $page['page_key'] === $reference['page_key'],
        ));
        if (count($pages) !== 1) {
            return false;
        }
        $page = $pages[0];

        return $reference['source_html_digest'] === $page['source_html']['sha256']
            && $reference['layout_ast_digest'] === $page['layout']['ast_digest']
            && $reference['layout_css_digest'] === $page['layout']['css_digest'];
    };

    if (! $layoutSnapshotMatches($candidateSnapshot)
        || $candidateSnapshot['validation']['status'] !== 'pending'
        || $candidateSnapshot['lineage'] !== null) {
        $errors[] = 'The candidate Layout Snapshot fixture must be content-addressed and retain explicit pending validation before freeze.';
    }
    if (! $layoutSnapshotMatches($frozenSnapshot)
        || $frozenSnapshot['validation'] !== $validationFixture
        || ($frozenSnapshot['lineage']['delta'] ?? null) !== $deltaFixture
        || ($frozenSnapshot['lineage']['parent_snapshot_id'] ?? null) !== $candidateSnapshot['snapshot_id']
        || ($frozenSnapshot['lineage']['parent_snapshot_digest'] ?? null) !== $candidateSnapshot['snapshot_digest']
        || ($deltaFixture['operations'][0]['operation'] ?? null) !== 'attach_validation'
        || ($deltaFixture['operations'][0]['before_digest'] ?? null) !== $candidateSnapshot['snapshot_digest']
        || ($deltaFixture['operations'][0]['after_digest'] ?? null) !== $layoutCanonicalDigest($validationFixture)
        || $frozenSnapshot['source_structure_digest'] !== $candidateSnapshot['source_structure_digest']
        || $layoutCanonicalJson($frozenSnapshot['constraints']) !== $layoutCanonicalJson($candidateSnapshot['constraints'])
        || $layoutCanonicalJson($frozenSnapshot['pages']) !== $layoutCanonicalJson($candidateSnapshot['pages'])) {
        $errors[] = 'The frozen Layout Snapshot fixture must bind passed viewport validation and its finite parent delta.';
    }
    if (! $layoutValidationMatches($validationFixture, $frozenSnapshot['constraints'], $frozenSnapshot['pages'])) {
        $errors[] = 'The Layout validation fixture must pass breakpoint and geometry checks at 390, 768, and 1440 CSS pixels.';
    }
    if (! $layoutDeltaMatches($deltaFixture)) {
        $errors[] = 'The Layout delta fixture must remain sequential, finite, digest-bound, and auditable.';
    }
    if (! $layoutDeltaMatches($finitePatchDeltaFixture)) {
        $errors[] = 'The finite Layout patch delta fixture must bind its canonical plan identity and preserve one ordered structured issue per operation.';
    } else {
        $patchedPageAst = $candidateSnapshot['pages'][0]['layout']['ast'];
        $patchedPageAst['rules'][0]['responsive']['wide']['layout']['gap'] = 'md';
        $patchedPageDigest1 = $layoutCanonicalDigest($patchedPageAst);
        $patchedPageAst['rules'][0]['responsive']['medium']['layout']['align'] = 'center';
        $patchedPageDigest2 = $layoutCanonicalDigest($patchedPageAst);
        $patchedConstraints = $candidateSnapshot['constraints'];
        $patchedConstraints['minimum_action_height_px'] = 48;
        if ($finitePatchDeltaFixture['patch_plan']['base_ast_digest'] !== $candidateSnapshot['pages'][0]['layout']['ast_digest']
            || $finitePatchDeltaFixture['operations'][0]['before_digest'] !== $candidateSnapshot['pages'][0]['layout']['ast_digest']
            || $finitePatchDeltaFixture['operations'][0]['after_digest'] !== $patchedPageDigest1
            || $finitePatchDeltaFixture['operations'][1]['before_digest'] !== $patchedPageDigest1
            || $finitePatchDeltaFixture['operations'][1]['after_digest'] !== $patchedPageDigest2
            || $finitePatchDeltaFixture['operations'][2]['before_digest'] !== $layoutCanonicalDigest($candidateSnapshot['constraints'])
            || $finitePatchDeltaFixture['operations'][2]['after_digest'] !== $layoutCanonicalDigest($patchedConstraints)) {
            $errors[] = 'Finite patch audit digests must identify page ASTs for rule operations and root constraints for $constraints operations.';
        }
    }
    $patchSnapshotRequestFixture = [
        'contract_version' => '1.0',
        'page_key' => 'home',
        'patch_plan' => $layoutPatchPlanFixture,
    ];
    if (! $layoutPatchSnapshotRequestMatches($patchSnapshotRequestFixture)) {
        $errors[] = 'The Layout Snapshot patch request must accept a non-empty digest-bound Layout plan for exactly one page.';
    }
    $emptyPatchRequest = $patchSnapshotRequestFixture;
    $emptyPatchRequest['patch_plan']['issues'] = [];
    if ($layoutPatchSnapshotRequestMatches($emptyPatchRequest)) {
        $errors[] = 'The Layout Snapshot patch endpoint must reject an empty review plan.';
    }
    $skinPatchRequest = $patchSnapshotRequestFixture;
    $skinPatchRequest['patch_plan']['stage'] = 'skin';
    if ($layoutPatchSnapshotRequestMatches($skinPatchRequest)) {
        $errors[] = 'The Layout Snapshot patch endpoint must reject non-Layout stage plans.';
    }
    if (! $layoutReferenceMatches($referenceFixture, $frozenSnapshot)) {
        $errors[] = 'The Layout reference fixture must resolve to exactly one Snapshot page and all four digests must agree.';
    }
    if ($candidateSnapshot['snapshot_digest'] !== '81a9ca8348559d8c45fffc20fd09b1d60aad7edb1de7b23f8f662ef144ac0f4d'
        || $frozenSnapshot['snapshot_digest'] !== '68577583df4a60182630fd1d1c55eb86436c164ca7f23164172814176d5ffc46') {
        $errors[] = 'Adding finite patch lineage must not rewrite the established candidate/frozen Layout Snapshot fixtures.';
    }

    $invalidFrozen = $candidateSnapshot;
    $invalidFrozen['status'] = 'frozen';
    $invalidFrozenPayload = $invalidFrozen;
    unset($invalidFrozenPayload['snapshot_id'], $invalidFrozenPayload['snapshot_digest']);
    $invalidFrozen['snapshot_digest'] = $layoutCanonicalDigest($invalidFrozenPayload);
    $invalidFrozen['snapshot_id'] = 'ls_'.$invalidFrozen['snapshot_digest'];
    if ($layoutSnapshotMatches($invalidFrozen)) {
        $errors[] = 'A frozen Layout Snapshot must reject pending validation even when its content address is otherwise valid.';
    }

    $invalidHtml = $frozenSnapshot;
    $invalidHtml['pages'][0]['source_html']['content_base64'] = base64_encode('<main>changed</main>');
    $invalidHtmlPayload = $invalidHtml;
    unset($invalidHtmlPayload['snapshot_id'], $invalidHtmlPayload['snapshot_digest']);
    $invalidHtml['snapshot_digest'] = $layoutCanonicalDigest($invalidHtmlPayload);
    $invalidHtml['snapshot_id'] = 'ls_'.$invalidHtml['snapshot_digest'];
    if ($layoutSnapshotMatches($invalidHtml)) {
        $errors[] = 'Layout Snapshot source_html.sha256 must hash the exact decoded HTML bytes.';
    }

    $invalidAst = $frozenSnapshot;
    $invalidAst['pages'][0]['layout']['ast']['surface'] = ['tone' => 'canvas'];
    $invalidAst['pages'][0]['layout']['ast_digest'] = $layoutCanonicalDigest($invalidAst['pages'][0]['layout']['ast']);
    $invalidAstPayload = $invalidAst;
    unset($invalidAstPayload['snapshot_id'], $invalidAstPayload['snapshot_digest']);
    $invalidAst['snapshot_digest'] = $layoutCanonicalDigest($invalidAstPayload);
    $invalidAst['snapshot_id'] = 'ls_'.$invalidAst['snapshot_digest'];
    if ($layoutSnapshotMatches($invalidAst)) {
        $errors[] = 'Geometry-only Layout Snapshot fixtures must reject paint or surface data even when every digest is recomputed.';
    }

    $invalidDelta = $deltaFixture;
    $invalidDelta['operations'] = array_fill(0, 101, $deltaFixture['operations'][0]);
    if ($layoutDeltaMatches($invalidDelta)) {
        $errors[] = 'Layout Snapshot deltas must reject more than 100 operations.';
    }

    $legacyWithPatch = $deltaFixture;
    $legacyWithPatch['operations'][0]['finite_patch'] = $finitePatchDeltaFixture['operations'][0]['finite_patch'];
    if ($layoutDeltaMatches($legacyWithPatch)) {
        $errors[] = 'Legacy Layout delta operations must reject the finite_patch field.';
    }
    $finiteWithoutPatch = $finitePatchDeltaFixture;
    unset($finiteWithoutPatch['operations'][0]['finite_patch']);
    if ($layoutDeltaMatches($finiteWithoutPatch)) {
        $errors[] = 'apply_finite_layout_patch must require its exact structured finite_patch issue.';
    }
    $foreignStagePatch = $finitePatchDeltaFixture;
    $foreignStagePatch['operations'][0]['finite_patch']['operation'] = ['type' => 'set_tone', 'value' => 'surface'];
    if ($layoutDeltaMatches($foreignStagePatch)) {
        $errors[] = 'Layout Snapshot lineage must reject finite patches owned by Skin, Decor, or Motion.';
    }
    $noOpPatch = $finitePatchDeltaFixture;
    $noOpPatch['operations'][0]['after_digest'] = $noOpPatch['operations'][0]['before_digest'];
    if ($layoutDeltaMatches($noOpPatch)) {
        $errors[] = 'A finite Layout patch must change the canonical AST digest.';
    }
    $wrongConstraintTarget = $finitePatchDeltaFixture;
    $wrongConstraintTarget['operations'][2]['target'] = ['kind' => 'page', 'page_key' => 'home'];
    if ($layoutDeltaMatches($wrongConstraintTarget)) {
        $errors[] = 'The $constraints finite patch target must bind the Layout Snapshot constraints target.';
    }
    $missingRootPatchPlan = $finitePatchDeltaFixture;
    unset($missingRootPatchPlan['patch_plan']);
    if ($layoutDeltaMatches($missingRootPatchPlan)) {
        $errors[] = 'An apply_finite_layout_patch-only delta must require its root patch_plan.';
    }
    $legacyRootPatchPlan = $deltaFixture;
    $legacyRootPatchPlan['patch_plan'] = $finitePatchDeltaFixture['patch_plan'];
    if ($layoutDeltaMatches($legacyRootPatchPlan)) {
        $errors[] = 'A legacy Layout delta must reject the root patch_plan field.';
    }
    $mixedPatchDelta = $finitePatchDeltaFixture;
    $mixedPatchDelta['operations'][2] = $deltaFixture['operations'][0];
    $mixedPatchDelta['operations'][2]['sequence'] = 3;
    if ($layoutDeltaMatches($mixedPatchDelta)) {
        $errors[] = 'Finite patch and legacy operations must not be mixed in one Layout delta.';
    }
    $mismatchedPlanIssue = $finitePatchDeltaFixture;
    $mismatchedPlanIssue['operations'][1]['finite_patch']['operation']['value'] = 'end';
    if ($layoutDeltaMatches($mismatchedPlanIssue)) {
        $errors[] = 'Every finite delta operation must exactly equal the patch_plan issue at the same sequence.';
    }
    $wrongPatchChangeId = $finitePatchDeltaFixture;
    $wrongPatchChangeId['change_id'] = 'layout-patch-'.str_repeat('f', 64);
    if ($layoutDeltaMatches($wrongPatchChangeId)) {
        $errors[] = 'A finite Layout delta change_id must derive from the canonical patch_plan SHA-256.';
    }

    $approvalArtifactInput = $layoutLoad('schemas/v1/approval/artifact-input.json');
    $approvalArtifact = $layoutLoad('schemas/v1/approval/artifact.json');
    $approvalInputTypes = $approvalArtifactInput['properties']['type']['enum'] ?? [];
    $approvalArtifactTypes = $approvalArtifact['properties']['type']['enum'] ?? [];
    $tier1 = $layoutLoad('openapi/tier1.json');
    $approvalTypeParameter = array_values(array_filter(
        $tier1['paths']['/v1/tenants/{tenant}/sites/{site}/approval-requests']['get']['parameters'] ?? [],
        static fn (array $parameter): bool => ($parameter['name'] ?? null) === 'artifact_type',
    ))[0] ?? [];
    if (! in_array('layout_snapshot', $approvalInputTypes, true)
        || $approvalInputTypes !== $approvalArtifactTypes
        || ! in_array('layout_snapshot', $approvalTypeParameter['schema']['enum'] ?? [], true)) {
        $errors[] = 'Approval input, resource, and list filtering must all recognize the additive layout_snapshot artifact type.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Layout Snapshot contracts: %s', $exception->getMessage());
}
