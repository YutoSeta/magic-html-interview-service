<?php

declare(strict_types=1);

/** @var string $root */
/** @var list<string> $errors */

try {
    $visualLoad = static fn (string $relativePath): array => json_decode(
        (string) file_get_contents($root.'/'.$relativePath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $planSchema = $visualLoad('schemas/v1/visual-feedback/patch-plan.json');
    $reviewRequestSchema = $visualLoad('schemas/v1/visual-feedback/review-request.json');
    $reviewResultSchema = $visualLoad('schemas/v1/visual-feedback/review-result.json');
    $qualityAssessmentSchema = $visualLoad('schemas/v1/visual-feedback/quality-assessment.json');
    $qualityEvaluationSchema = $visualLoad('schemas/v1/visual-feedback/design-quality-evaluation.json');
    $qualityResultSchema = $visualLoad('schemas/v1/visual-feedback/quality-result.json');
    $loopContextSchema = $visualLoad('schemas/v1/visual-feedback/loop-context.json');
    $loopResultSchema = $visualLoad('schemas/v1/visual-feedback/loop-result.json');
    $reviewJobSchema = $visualLoad('schemas/v1/visual-feedback/review-job.json');
    $patchRequestSchema = $visualLoad('schemas/v1/visual-feedback/patch-request.json');
    $patchResultSchema = $visualLoad('schemas/v1/visual-feedback/patch-result.json');
    $stageSubjectReferenceSchema = $visualLoad('schemas/v1/styler/stage-subject-reference.json');
    $stageResultSchema = $visualLoad('schemas/v1/styler/stage-result.json');
    $stageJobSchema = $visualLoad('schemas/v1/styler/stage-job.json');
    $screenshotRequestSchema = $visualLoad('schemas/v1/preview/screenshot-request.json');
    $screenshotResultSchema = $visualLoad('schemas/v1/preview/screenshot-result.json');
    $browserEvidenceRequestSchema = $visualLoad('schemas/v1/preview/browser-evidence-request.json');
    $browserEvidenceResultSchema = $visualLoad('schemas/v1/preview/browser-evidence-result.json');
    $browserEvidenceSchema = $visualLoad('schemas/v1/visual-feedback/browser-evidence.json');
    $pipelineJobSchema = $visualLoad('schemas/v1/styler/pipeline-job.json');
    $tier1Schema = $visualLoad('openapi/tier1.json');
    $planFixture = $visualLoad('tests/fixtures/visual-feedback/patch-plan.json');
    $qualityEvaluationFixture = $visualLoad('tests/fixtures/visual-feedback/design-quality-evaluation.json');
    $qualityResultFixture = $visualLoad('tests/fixtures/visual-feedback/quality-result.json');
    $loopContextFixture = $visualLoad('tests/fixtures/visual-feedback/loop-context.json');
    $loopResultFixture = $visualLoad('tests/fixtures/visual-feedback/loop-result.json');
    $stageSubjectReferenceFixture = $visualLoad('tests/fixtures/visual-feedback/stage-subject-reference.json');

    $visualClosed = static function (array $value, array $expected): bool {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    };
    $visualSchemaRoot = static function (array $schema, array $properties, array $required) use ($visualClosed): bool {
        return ($schema['additionalProperties'] ?? null) === false
            && $visualClosed($schema['properties'] ?? [], $properties)
            && ($schema['required'] ?? null) === $required;
    };
    $operationTypes = [
        'layout' => [
            'set_cluster_wrap', 'set_flow', 'set_columns', 'set_column_ratio', 'set_gap',
            'set_section_spacing', 'set_alignment', 'set_image_aspect', 'set_object_fit',
            'set_container_max_width', 'set_content_max_width', 'set_minimum_action_height',
        ],
        'skin' => [
            'set_surface_background', 'set_surface_foreground', 'set_tone',
            'set_font_family', 'set_color_token',
        ],
        'decor' => [
            'set_radius', 'set_shadow', 'set_border', 'set_outline', 'set_opacity', 'set_accent',
        ],
        'motion' => [
            'set_duration', 'set_easing', 'set_delay', 'set_transition_property',
            'set_tween_transform', 'set_tween_opacity', 'disable_motion',
        ],
    ];
    $problemsByStage = [
        'layout' => [
            'unintended_wrap', 'horizontal_overflow', 'overlap', 'clipped_content', 'zero_dimension',
            'container_too_wide', 'container_too_narrow', 'content_too_wide', 'content_too_narrow',
            'spacing_too_tight', 'spacing_too_loose', 'misalignment',
            'weak_hierarchy', 'column_imbalance', 'incorrect_reflow', 'incorrect_mobile_order', 'image_aspect_mismatch',
            'object_fit_mismatch', 'form_control_too_small', 'action_too_small',
        ],
        'skin' => ['insufficient_contrast', 'inconsistent_surface', 'inconsistent_type_voice', 'weak_hierarchy'],
        'decor' => ['excessive_decoration', 'missing_decoration', 'inconsistent_radius', 'heavy_shadow', 'weak_hierarchy'],
        'motion' => ['motion_too_fast', 'motion_too_slow', 'motion_distracting', 'motion_missing', 'reduced_motion_risk'],
    ];
    $problems = array_values(array_unique(array_merge(...array_values($problemsByStage))));
    $schemaOperationTypes = $planSchema['$defs']['operation']['properties']['type']['enum'] ?? [];
    $expectedOperationTypes = array_merge(...array_values($operationTypes));
    if (! $visualSchemaRoot(
        $planSchema,
        ['contract_version', 'stage', 'base_ast_digest', 'issues'],
        ['contract_version', 'stage', 'base_ast_digest', 'issues'],
    )
        || ($planSchema['properties']['contract_version']['const'] ?? null) !== '1.0'
        || ($planSchema['properties']['stage']['enum'] ?? null) !== ['layout', 'skin', 'decor', 'motion']
        || ($planSchema['properties']['issues']['minItems'] ?? null) !== 0
        || ($planSchema['properties']['issues']['maxItems'] ?? null) !== 20
        || ($planSchema['$defs']['issue']['additionalProperties'] ?? null) !== false
        || ($planSchema['$defs']['operation']['additionalProperties'] ?? null) !== false
        || $schemaOperationTypes !== $expectedOperationTypes
        || ($planSchema['$defs']['problem']['enum'] ?? null) !== $problems
        || ($planSchema['$defs']['layoutProblem']['enum'] ?? null) !== $problemsByStage['layout']
        || ($planSchema['$defs']['skinProblem']['enum'] ?? null) !== $problemsByStage['skin']
        || ($planSchema['$defs']['decorProblem']['enum'] ?? null) !== $problemsByStage['decor']
        || ($planSchema['$defs']['motionProblem']['enum'] ?? null) !== $problemsByStage['motion']
        || ($planSchema['allOf'][0]['then']['properties']['issues']['items']['properties']['problem']['$ref'] ?? null) !== '#/$defs/layoutProblem'
        || ($planSchema['allOf'][1]['then']['properties']['issues']['items']['properties']['problem']['$ref'] ?? null) !== '#/$defs/skinProblem'
        || ($planSchema['allOf'][2]['then']['properties']['issues']['items']['properties']['problem']['$ref'] ?? null) !== '#/$defs/decorProblem'
        || ($planSchema['allOf'][3]['then']['properties']['issues']['items']['properties']['problem']['$ref'] ?? null) !== '#/$defs/motionProblem'
        || ($planSchema['$defs']['layoutOperation']['oneOf'][0]['properties']['type']['const'] ?? null) !== 'set_cluster_wrap'
        || ($planSchema['$defs']['layoutOperation']['oneOf'][0]['properties']['value']['enum'] ?? null) !== ['wrap', 'nowrap']) {
        $errors[] = 'Visual feedback patch plans must be closed, digest-bound, finite, and stage-owned, including explicit cluster wrap values.';
    }

    $tokenMatches = static fn (mixed $value): bool => is_string($value)
        && preg_match('/^(?:[a-z]|[0-9]+[a-z])[a-z0-9-]{0,99}$/', $value) === 1;
    $safeValueMatches = static fn (mixed $value): bool => is_string($value)
        && strlen($value) >= 1
        && strlen($value) <= 200
        && ! str_contains($value, '{')
        && ! str_contains($value, '}')
        && ! str_contains($value, ';')
        && ! str_contains($value, '!important');
    $operationValueMatches = static function (string $type, mixed $value) use ($tokenMatches, $safeValueMatches, $visualClosed): bool {
        return match ($type) {
            'set_cluster_wrap' => in_array($value, ['wrap', 'nowrap'], true),
            'set_flow' => in_array($value, ['stack', 'cluster', 'row', 'grid', 'split', 'overlay'], true),
            'set_columns' => in_array($value, ['one', 'two', 'three', 'auto-fit'], true),
            'set_column_ratio' => is_string($value)
                && preg_match('/^(?:[0-9]*\.?[0-9]+fr)(?:\s+[0-9]*\.?[0-9]+fr){1,5}$/', $value) === 1,
            'set_gap', 'set_section_spacing', 'set_image_aspect',
            'set_duration', 'set_easing', 'set_delay' => $tokenMatches($value),
            'set_alignment' => in_array($value, ['start', 'center', 'end', 'stretch', 'baseline'], true),
            'set_object_fit' => in_array($value, ['cover', 'contain', 'fill', 'none', 'scale-down'], true),
            'set_container_max_width' => is_int($value) && $value >= 320 && $value <= 3840,
            'set_content_max_width' => is_int($value) && $value >= 240 && $value <= 3840,
            'set_minimum_action_height' => is_int($value) && $value >= 44 && $value <= 256,
            'set_surface_background', 'set_surface_foreground', 'set_font_family',
            'set_radius', 'set_shadow', 'set_border' => $tokenMatches($value),
            'set_tone' => in_array($value, ['canvas', 'surface', 'raised', 'inverse'], true),
            'set_transition_property' => is_array($value)
                && array_is_list($value)
                && count($value) >= 1
                && count($value) <= 8
                && count($value) === count(array_unique($value, SORT_REGULAR))
                && array_filter($value, static fn (mixed $property): bool => ! in_array(
                    $property,
                    ['transform', 'opacity', 'background', 'color', 'box-shadow', 'border-color', 'translate', 'scale'],
                    true,
                )) === [],
            'disable_motion' => $value === true,
            'set_accent' => is_array($value)
                && ! array_is_list($value)
                && isset($value['kind'])
                && $visualClosed($value, array_values(array_intersect(['kind', 'tone', 'thickness', 'length'], array_keys($value))))
                && in_array($value['kind'], ['underline', 'side-bar', 'top-bar', 'dot'], true)
                && (! isset($value['tone']) || $tokenMatches($value['tone']))
                && (! isset($value['thickness']) || (is_string($value['thickness'])
                    && preg_match('/^(?:[0-9]*\\.?[0-9]+)(?:px|rem|em)$/', $value['thickness']) === 1))
                && (! isset($value['length']) || (is_string($value['length'])
                    && preg_match('/^(?:full|(?:[0-9]*\\.?[0-9]+)(?:px|rem|em|%|ch))$/', $value['length']) === 1)),
            default => $safeValueMatches($value),
        };
    };
    $visualPlanMatches = static function (mixed $plan) use (
        $operationTypes,
        $operationValueMatches,
        $problemsByStage,
        $visualClosed,
    ): bool {
        if (! is_array($plan)
            || array_is_list($plan)
            || ! $visualClosed($plan, ['contract_version', 'stage', 'base_ast_digest', 'issues'])
            || $plan['contract_version'] !== '1.0'
            || ! isset($operationTypes[$plan['stage']])
            || ! is_string($plan['base_ast_digest'])
            || preg_match('/^[a-f0-9]{64}$/', $plan['base_ast_digest']) !== 1
            || ! is_array($plan['issues'])
            || ! array_is_list($plan['issues'])
            || count($plan['issues']) > 20) {
            return false;
        }
        $seen = [];
        foreach ($plan['issues'] as $issue) {
            if (! is_array($issue)
                || array_is_list($issue)
                || ! $visualClosed($issue, ['target_id', 'viewport', 'problem', 'operation'])
                || ! is_string($issue['target_id'])
                || preg_match('/^(?:\$constraints|[a-z][a-z0-9._-]{0,199})$/', $issue['target_id']) !== 1
                || ! in_array($issue['problem'], $problemsByStage[$plan['stage']], true)
                || ! is_array($issue['viewport'])
                || array_is_list($issue['viewport'])
                || count($issue['viewport']) < 1
                || count($issue['viewport']) > 2
                || array_diff(array_keys($issue['viewport']), ['min_width', 'max_width']) !== []
                || ! is_array($issue['operation'])
                || array_is_list($issue['operation'])
                || ! $visualClosed($issue['operation'], ['type', 'value'])
                || ! in_array($issue['operation']['type'], $operationTypes[$plan['stage']], true)
                || ! $operationValueMatches($issue['operation']['type'], $issue['operation']['value'])) {
                return false;
            }
            foreach ($issue['viewport'] as $bound) {
                if (! is_int($bound) || $bound < 320 || $bound > 7680) {
                    return false;
                }
            }
            if (isset($issue['viewport']['min_width'], $issue['viewport']['max_width'])
                && $issue['viewport']['min_width'] > $issue['viewport']['max_width']) {
                return false;
            }
            $operationType = $issue['operation']['type'];
            $targetsSiteConstraints = $issue['target_id'] === '$constraints';
            $globalOnlyOperations = [
                'set_container_max_width', 'set_content_max_width', 'set_minimum_action_height',
                'set_color_token', 'set_accent',
                'set_duration', 'set_easing', 'set_delay', 'set_transition_property',
                'set_tween_transform', 'set_tween_opacity', 'disable_motion',
            ];
            if (in_array($operationType, $globalOnlyOperations, true)
                && $issue['viewport'] !== ['min_width' => 320]) {
                return false;
            }
            $constraintOperations = [
                'set_container_max_width', 'set_content_max_width', 'set_minimum_action_height',
            ];
            if ((in_array($operationType, $constraintOperations, true)
                    && (! $targetsSiteConstraints || $issue['viewport'] !== ['min_width' => 320]))
                || (! in_array($operationType, $constraintOperations, true) && $targetsSiteConstraints)) {
                return false;
            }
            $signature = $issue['target_id'].'|'.json_encode($issue['viewport'], JSON_THROW_ON_ERROR).'|'.$issue['operation']['type'];
            if (isset($seen[$signature])) {
                return false;
            }
            $seen[$signature] = true;
        }

        return true;
    };

    if (! $visualPlanMatches($planFixture)) {
        $errors[] = 'The visual-feedback fixture must be a valid finite Layout patch plan.';
    }
    $wrongStage = $planFixture;
    $wrongStage['stage'] = 'skin';
    if ($visualPlanMatches($wrongStage)) {
        $errors[] = 'Visual feedback must reject an operation owned by a different stage.';
    }
    $wrongProblemStage = $planFixture;
    $wrongProblemStage['issues'][0]['problem'] = 'insufficient_contrast';
    if ($visualPlanMatches($wrongProblemStage)) {
        $errors[] = 'Visual feedback must reject a problem owned by a different stage.';
    }
    $arbitraryProblem = $planFixture;
    $arbitraryProblem['issues'][0]['problem'] = 'Please rewrite all CSS until it looks better';
    if ($visualPlanMatches($arbitraryProblem)) {
        $errors[] = 'Visual feedback must reject free-form correction instructions.';
    }
    $unsafeValue = $planFixture;
    $unsafeValue['issues'][0]['operation'] = ['type' => 'set_column_ratio', 'value' => '1fr; color:red'];
    if ($visualPlanMatches($unsafeValue)) {
        $errors[] = 'Visual feedback operations must reject values outside their type-specific schema.';
    }
    $scopedSiteConstraint = $planFixture;
    $scopedSiteConstraint['issues'][0] = [
        'target_id' => '$constraints',
        'viewport' => ['max_width' => 719],
        'problem' => 'action_too_small',
        'operation' => ['type' => 'set_minimum_action_height', 'value' => 48],
    ];
    if ($visualPlanMatches($scopedSiteConstraint)) {
        $errors[] = 'A site Layout constraint patch must use the canonical all-device viewport.';
    }
    $scopedColorToken = [
        'contract_version' => '1.0',
        'stage' => 'skin',
        'base_ast_digest' => str_repeat('a', 64),
        'issues' => [[
            'target_id' => 'brand',
            'viewport' => ['min_width' => 640, 'max_width' => 1023],
            'problem' => 'insufficient_contrast',
            'operation' => ['type' => 'set_color_token', 'value' => '#172033'],
        ]],
    ];
    if ($visualPlanMatches($scopedColorToken)) {
        $errors[] = 'Token-wide Skin corrections must use the canonical all-device viewport.';
    }

    if (! $visualSchemaRoot(
        $reviewRequestSchema,
        ['contract_version', 'stage', 'base_ast_digest', 'layout_snapshot_ref', 'stage_subject_ref', 'screenshots', 'geometry_reports', 'browser_evidence', 'loop_context', 'execution_profile'],
        ['contract_version', 'stage', 'base_ast_digest', 'screenshots'],
    )
        || ($reviewRequestSchema['properties']['layout_snapshot_ref']['$ref'] ?? null) !== '../layout/reference.json'
        || ($reviewRequestSchema['properties']['stage_subject_ref']['$ref'] ?? null) !== '../styler/stage-subject-reference.json'
        || ($reviewRequestSchema['properties']['geometry_reports']['items']['allOf'][0]['$ref'] ?? null) !== '../preview/layout-validation-result.json'
        || ($reviewRequestSchema['properties']['geometry_reports']['items']['allOf'][1]['properties']['contract_version']['const'] ?? null) !== '1.2'
        || ($reviewRequestSchema['properties']['browser_evidence']['$ref'] ?? null) !== 'browser-evidence.json'
        || ! str_contains((string) ($reviewRequestSchema['properties']['browser_evidence']['description'] ?? ''), 'fails automated evidence coverage closed')
        || count($reviewRequestSchema['oneOf'] ?? []) !== 2
        || ($reviewRequestSchema['oneOf'][0]['properties']['stage']['const'] ?? null) !== 'layout'
        || ($reviewRequestSchema['oneOf'][0]['properties']['screenshots']['items']['properties']['preview_snapshot_id']['pattern'] ?? null) !== '^ls_[a-f0-9]{64}$'
        || ($reviewRequestSchema['oneOf'][0]['required'] ?? null) !== ['layout_snapshot_ref']
        || ($reviewRequestSchema['oneOf'][1]['properties']['stage']['enum'] ?? null) !== ['skin', 'decor', 'motion']
        || ($reviewRequestSchema['oneOf'][1]['properties']['screenshots']['items']['properties']['preview_snapshot_id']['pattern'] ?? null) !== '^ast_[a-f0-9]{64}$'
        || ($reviewRequestSchema['oneOf'][1]['required'] ?? null) !== ['stage_subject_ref']
        || count($reviewRequestSchema['oneOf'][1]['allOf'] ?? []) !== 3
        || ($reviewRequestSchema['oneOf'][1]['allOf'][0]['then']['properties']['stage_subject_ref']['properties']['stage']['const'] ?? null) !== 'skin'
        || ($reviewRequestSchema['oneOf'][1]['allOf'][1]['then']['properties']['stage_subject_ref']['properties']['stage']['const'] ?? null) !== 'decor'
        || ($reviewRequestSchema['oneOf'][1]['allOf'][2]['then']['properties']['stage_subject_ref']['properties']['stage']['const'] ?? null) !== 'motion'
        || ($reviewRequestSchema['$defs']['screenshot']['additionalProperties'] ?? null) !== false
        || ($reviewRequestSchema['$defs']['screenshot']['required'] ?? null) !== ['source', 'preview_id', 'preview_snapshot_id', 'entry_path', 'viewport', 'full_page', 'capture_state', 'mime', 'sha256', 'content_base64', 'capture_receipt']
        || ($reviewRequestSchema['$defs']['screenshot']['properties']['source']['enum'] ?? null) !== ['local', 'cloudflare']
        || ($reviewRequestSchema['$defs']['screenshot']['properties']['preview_id']['format'] ?? null) !== 'uuid'
        || ($reviewRequestSchema['$defs']['screenshot']['properties']['preview_snapshot_id']['maxLength'] ?? null) !== 255
        || ($reviewRequestSchema['$defs']['screenshot']['properties']['entry_path']['$ref'] ?? null) !== '#/$defs/entryPath'
        || ($reviewRequestSchema['$defs']['screenshot']['properties']['full_page']['type'] ?? null) !== 'boolean'
        || ($reviewRequestSchema['$defs']['screenshot']['properties']['capture_state']['$ref'] ?? null) !== '#/$defs/captureState'
        || ($reviewRequestSchema['$defs']['screenshot']['properties']['mime']['enum'] ?? null) !== ['image/png', 'image/jpeg']
        || ($reviewRequestSchema['$defs']['screenshot']['properties']['content_base64']['maxLength'] ?? null) !== 11184812
        || ($reviewRequestSchema['$defs']['screenshot']['properties']['capture_receipt']['maxLength'] ?? null) !== 4096
        || ! str_contains((string) ($reviewRequestSchema['properties']['screenshots']['description'] ?? ''), 'preview_snapshot_id')
        || ! str_contains((string) ($reviewRequestSchema['properties']['screenshots']['description'] ?? ''), 'ast_<base_ast_digest>')
        || ($reviewRequestSchema['$defs']['entryPath']['maxLength'] ?? null) !== 1000
        || ($reviewRequestSchema['$defs']['captureState']['additionalProperties'] ?? null) !== false
        || ($reviewRequestSchema['$defs']['captureState']['required'] ?? null) !== ['kind', 'target_id', 'elapsed_ms']
        || ($reviewRequestSchema['$defs']['captureState']['properties']['kind']['enum'] ?? null) !== ['rest', 'enter']
        || ($reviewRequestSchema['$defs']['captureState']['properties']['elapsed_ms']['maximum'] ?? null) !== 5000
        || ($reviewRequestSchema['$defs']['captureState']['properties']['target_id']['type'] ?? null) !== 'null'
        || ($reviewRequestSchema['$defs']['captureState']['allOf'][0]['then']['properties']['elapsed_ms']['const'] ?? null) !== 0
        || ! str_contains((string) ($reviewRequestSchema['properties']['loop_context']['description'] ?? ''), 'server-owned')
        || ! str_contains((string) ($reviewRequestSchema['properties']['loop_context']['description'] ?? ''), 'service-authenticated')
        || ! $visualSchemaRoot($reviewResultSchema, ['contract_version', 'verdict', 'scorecard', 'decision', 'patch_plan', 'diagnostics'], ['contract_version', 'verdict', 'scorecard', 'decision', 'patch_plan', 'diagnostics'])
        || ($reviewResultSchema['properties']['verdict']['enum'] ?? null) !== ['passed', 'patch_required', 'human_review_required']
        || ($reviewResultSchema['properties']['scorecard']['$ref'] ?? null) !== 'quality-result.json'
        || ($reviewResultSchema['properties']['decision']['$ref'] ?? null) !== 'loop-result.json'
        || ($reviewResultSchema['properties']['patch_plan']['$ref'] ?? null) !== 'patch-plan.json'
        || ($reviewResultSchema['properties']['diagnostics']['properties']['next_loop_context']['oneOf'][1]['$ref'] ?? null) !== 'loop-context.json'
        || ($reviewResultSchema['properties']['diagnostics']['properties']['next_loop_state_digest']['oneOf'][1]['pattern'] ?? null) !== '^[a-f0-9]{64}$'
        || ($reviewResultSchema['allOf'][0]['then']['properties']['patch_plan']['properties']['issues']['maxItems'] ?? null) !== 0
        || ($reviewResultSchema['allOf'][0]['then']['properties']['decision']['properties']['verdict']['const'] ?? null) !== 'passed'
        || ($reviewResultSchema['allOf'][0]['then']['properties']['decision']['properties']['stop']['const'] ?? null) !== true
        || ($reviewResultSchema['allOf'][0]['else']['then']['properties']['patch_plan']['properties']['issues']['minItems'] ?? null) !== 1
        || ($reviewResultSchema['allOf'][0]['else']['then']['properties']['decision']['properties']['verdict']['const'] ?? null) !== 'patch_required'
        || ($reviewResultSchema['allOf'][0]['else']['then']['properties']['decision']['properties']['stop']['const'] ?? null) !== false
        || ($reviewResultSchema['allOf'][0]['else']['else']['properties']['decision']['properties']['verdict']['const'] ?? null) !== 'human_review_required'
        || ($reviewResultSchema['allOf'][0]['else']['else']['properties']['decision']['properties']['stop']['const'] ?? null) !== true
        || ($qualityAssessmentSchema['$ref'] ?? null) !== 'design-quality-evaluation.json'
        || ! $visualSchemaRoot(
            $qualityEvaluationSchema,
            ['profile', 'subject_digest', 'objective_digest', 'rubric_digest', 'evidence_digest', 'coverage', 'browser', 'screenshot_reviewer', 'agentic'],
            ['profile', 'subject_digest', 'objective_digest', 'rubric_digest', 'evidence_digest', 'coverage', 'browser', 'screenshot_reviewer', 'agentic'],
        )
        || ($qualityEvaluationSchema['properties']['profile']['const'] ?? null) !== 'balanced-order-richness-v1'
        || ($qualityEvaluationSchema['properties']['browser']['allOf'][1]['required'] ?? null) !== ['subject_digest', 'evidence_digest', 'accessibility', 'gates']
        || ($qualityEvaluationSchema['properties']['screenshot_reviewer']['required'] ?? null) !== ['subject_digest', 'evidence_digest', 'order', 'richness', 'gates']
        || ($qualityEvaluationSchema['properties']['agentic']['required'] ?? null) !== ['subject_digest', 'objective_digest', 'rubric_digest', 'evidence_digest', 'purpose_fit', 'gates']
        || ($qualityEvaluationSchema['$defs']['gate']['properties']['outcome']['enum'] ?? null) !== ['passed', 'failed', 'not_applicable', 'insufficient_evidence']
        || ($qualityResultSchema['properties']['profile']['const'] ?? null) !== 'balanced-order-richness-v1'
        || ($qualityResultSchema['properties']['algorithm_version']['const'] ?? null) !== '1.1.0'
        || ($qualityResultSchema['additionalProperties'] ?? null) !== false
        || ($qualityResultSchema['properties']['source_evidence_digests']['required'] ?? null) !== ['browser', 'screenshot_reviewer', 'agentic']
        || ($qualityResultSchema['properties']['evidence_bindings']['required'] ?? null) !== ['hard_gates', 'accessibility', 'order', 'richness', 'purpose_fit']
        || ($qualityResultSchema['properties']['scores_bp']['$ref'] ?? null) !== '#/$defs/aggregateScoresBp'
        || ($qualityResultSchema['properties']['scorecard_digest']['$ref'] ?? null) !== '#/$defs/digest'
        || ($loopContextSchema['properties']['evaluations_used']['maximum'] ?? null) !== 4
        || ($loopContextSchema['properties']['max_evaluations']['default'] ?? null) !== 4
        || ($loopContextSchema['properties']['max_accepted_patches']['maximum'] ?? null) !== 3
        || ($loopContextSchema['properties']['max_accepted_patches']['default'] ?? null) !== 3
        || ($loopContextSchema['properties']['max_model_calls']['default'] ?? null) !== 12
        || ($loopContextSchema['properties']['max_elapsed_ms']['default'] ?? null) !== 900000
        || ($loopContextSchema['properties']['history']['maxItems'] ?? null) !== 3
        || ($loopContextSchema['properties']['state_digest']['$ref'] ?? null) !== '#/$defs/digest'
        || ! str_contains((string) ($loopContextSchema['description'] ?? ''), 'not authentication')
        || ($loopResultSchema['properties']['policy_version']['const'] ?? null) !== '1.3.0'
        || ($loopResultSchema['properties']['minimum_score_improvement_bp']['const'] ?? null) !== 200
        || ($loopResultSchema['properties']['max_evaluations']['maximum'] ?? null) !== 4
        || ($loopResultSchema['properties']['max_accepted_patches']['maximum'] ?? null) !== 3
        || ($loopResultSchema['properties']['history_entry']['$ref'] ?? null) !== 'loop-context.json#/$defs/historyEntry'
        || ($loopResultSchema['properties']['best_so_far']['$ref'] ?? null) !== 'loop-context.json#/$defs/historyEntry'
        || ($loopResultSchema['properties']['verdict']['enum'] ?? null) !== ['passed', 'patch_required', 'human_review_required']
        || ! $visualSchemaRoot($patchRequestSchema, ['contract_version', 'patch_plan'], ['contract_version', 'patch_plan'])
        || ($patchRequestSchema['properties']['patch_plan']['$ref'] ?? null) !== 'patch-plan.json'
        || ! $visualSchemaRoot(
            $patchResultSchema,
            ['contract_version', 'patch_id', 'stage', 'source_job_id', 'stage_ast', 'stage_css', 'css', 'stage_bundle', 'stage_subject_ref', 'patch_plan', 'delta'],
            ['contract_version', 'patch_id', 'stage', 'source_job_id', 'stage_ast', 'stage_css', 'css', 'stage_bundle', 'stage_subject_ref', 'patch_plan', 'delta'],
        )
        || ($patchResultSchema['properties']['patch_id']['format'] ?? null) !== 'uuid'
        || ($patchResultSchema['properties']['source_job_id']['format'] ?? null) !== 'uuid'
        || ($patchResultSchema['properties']['stage']['enum'] ?? null) !== ['skin', 'decor', 'motion']
        || ($patchResultSchema['properties']['stage_subject_ref']['$ref'] ?? null) !== '../styler/stage-subject-reference.json'
        || count($patchResultSchema['allOf'] ?? []) !== 3
        || ($patchResultSchema['allOf'][0]['then']['properties']['stage_subject_ref']['properties']['stage']['const'] ?? null) !== 'skin'
        || ($patchResultSchema['allOf'][1]['then']['properties']['stage_subject_ref']['properties']['stage']['const'] ?? null) !== 'decor'
        || ($patchResultSchema['allOf'][2]['then']['properties']['stage_subject_ref']['properties']['stage']['const'] ?? null) !== 'motion'
        || ($patchResultSchema['$defs']['delta']['additionalProperties'] ?? null) !== false
        || ($patchResultSchema['$defs']['delta']['required'] ?? null) !== ['base_ast_digest', 'result_ast_digest', 'changed', 'operations']
        || ($patchResultSchema['$defs']['delta']['properties']['operations']['maxItems'] ?? null) !== 20
        || ($patchResultSchema['$defs']['delta']['properties']['operations']['items']['$ref'] ?? null) !== '#/$defs/appliedOperation'
        || ($patchResultSchema['$defs']['appliedOperation']['additionalProperties'] ?? null) !== false
        || ($patchResultSchema['$defs']['appliedOperation']['required'] ?? null) !== ['sequence', 'target_id', 'viewport', 'problem', 'operation', 'before_ast_digest', 'after_ast_digest']) {
        $errors[] = 'Visual review and deterministic patch schemas must retain their exact closed envelopes, bounded screenshots, shared patch plan, and auditable delta.';
    }

    if (! $visualSchemaRoot(
        $stageSubjectReferenceSchema,
        ['profile', 'stage', 'job_id', 'context_digest', 'ast_digest'],
        ['profile', 'stage', 'job_id', 'context_digest', 'ast_digest'],
    )
        || ($stageSubjectReferenceSchema['properties']['profile']['const'] ?? null) !== 'styler-stage-subject-reference-v1'
        || ($stageSubjectReferenceSchema['properties']['stage']['enum'] ?? null) !== ['skin', 'decor', 'motion']
        || ($stageSubjectReferenceSchema['properties']['job_id']['format'] ?? null) !== 'uuid'
        || ($stageSubjectReferenceSchema['properties']['context_digest']['pattern'] ?? null) !== '^[a-f0-9]{64}$'
        || ($stageSubjectReferenceSchema['properties']['ast_digest']['pattern'] ?? null) !== '^[a-f0-9]{64}$'
        || ! $visualClosed($stageSubjectReferenceFixture, $stageSubjectReferenceSchema['required'] ?? [])
        || ($stageSubjectReferenceFixture['profile'] ?? null) !== 'styler-stage-subject-reference-v1'
        || ! $visualSchemaRoot(
            $stageResultSchema,
            ['annotated_html', 'stage_ast', 'stage_css', 'css', 'stage_bundle', 'stage_subject_ref', 'diagnostics', 'refinement'],
            ['annotated_html', 'stage_ast', 'stage_css', 'css', 'stage_bundle', 'stage_subject_ref', 'diagnostics', 'refinement'],
        )
        || ($stageResultSchema['properties']['stage_subject_ref']['$ref'] ?? null) !== 'stage-subject-reference.json'
        || ($stageResultSchema['properties']['stage_bundle']['$ref'] ?? null) !== 'stage-bundle.json'
        || ! $visualSchemaRoot(
            $stageJobSchema,
            ['contract_version', 'id', 'status', 'result', 'error', 'created_at', 'updated_at'],
            ['contract_version', 'id', 'status', 'created_at', 'updated_at'],
        )
        || ($stageJobSchema['properties']['result']['$ref'] ?? null) !== 'stage-result.json'
        || ($stageJobSchema['allOf'][0]['then']['required'] ?? null) !== ['result', 'error']) {
        $errors[] = 'Succeeded Styler stage jobs and finite patches must expose one exact server-owned stage subject reference for non-Layout visual review.';
    }

    if (! $visualClosed($qualityEvaluationFixture, $qualityEvaluationSchema['required'])
        || ! $visualClosed($qualityEvaluationFixture['coverage'] ?? [], ['required_ids', 'observed_ids'])
        || ! $visualClosed($qualityEvaluationFixture['browser'] ?? [], ['subject_digest', 'evidence_digest', 'accessibility', 'gates'])
        || ! $visualClosed($qualityEvaluationFixture['screenshot_reviewer'] ?? [], ['subject_digest', 'evidence_digest', 'order', 'richness', 'gates'])
        || ! $visualClosed($qualityEvaluationFixture['agentic'] ?? [], ['subject_digest', 'objective_digest', 'rubric_digest', 'evidence_digest', 'purpose_fit', 'gates'])
        || ($qualityEvaluationFixture['subject_digest'] ?? null) !== ($qualityEvaluationFixture['browser']['subject_digest'] ?? null)
        || ($qualityEvaluationFixture['subject_digest'] ?? null) !== ($qualityEvaluationFixture['screenshot_reviewer']['subject_digest'] ?? null)
        || ($qualityEvaluationFixture['subject_digest'] ?? null) !== ($qualityEvaluationFixture['agentic']['subject_digest'] ?? null)
        || ($qualityEvaluationFixture['objective_digest'] ?? null) !== ($qualityEvaluationFixture['agentic']['objective_digest'] ?? null)
        || ($qualityEvaluationFixture['rubric_digest'] ?? null) !== ($qualityEvaluationFixture['agentic']['rubric_digest'] ?? null)
        || array_diff($qualityEvaluationFixture['coverage']['required_ids'] ?? [], $qualityEvaluationFixture['coverage']['observed_ids'] ?? []) !== []) {
        $errors[] = 'The design-quality evaluation fixture must bind browser, screenshot-reviewer, and objective-bound agentic evidence to one covered subject.';
    }

    if (! $visualClosed($qualityResultFixture, $qualityResultSchema['required'])
        || ($qualityResultFixture['algorithm_version'] ?? null) !== '1.1.0'
        || ($qualityResultFixture['evidence_coverage']['complete'] ?? null) !== true
        || ($qualityResultFixture['source_evidence_digests'] ?? null) !== [
            'browser' => $qualityEvaluationFixture['browser']['evidence_digest'],
            'screenshot_reviewer' => $qualityEvaluationFixture['screenshot_reviewer']['evidence_digest'],
            'agentic' => $qualityEvaluationFixture['agentic']['evidence_digest'],
        ]
        || ($qualityResultFixture['subject_digest'] ?? null) !== ($qualityEvaluationFixture['subject_digest'] ?? null)
        || ($qualityResultFixture['objective_digest'] ?? null) !== ($qualityEvaluationFixture['objective_digest'] ?? null)
        || ($qualityResultFixture['rubric_digest'] ?? null) !== ($qualityEvaluationFixture['rubric_digest'] ?? null)
        || ($qualityResultFixture['evidence_digest'] ?? null) !== ($qualityEvaluationFixture['evidence_digest'] ?? null)
        || ! is_int($qualityResultFixture['scores_bp']['final'] ?? null)
        || ! is_string($qualityResultFixture['scorecard_digest'] ?? null)) {
        $errors[] = 'The quality-result fixture must expose the exact Design Core 1.1 scorecard envelope and source evidence bindings.';
    }

    if (! $visualClosed($loopContextFixture, $loopContextSchema['required'])
        || ($loopContextFixture['evaluations_used'] ?? null) !== count($loopContextFixture['history'] ?? []) + 1
        || ($loopContextFixture['max_evaluations'] ?? null) !== 4
        || ($loopContextFixture['max_accepted_patches'] ?? null) !== 3
        || ! $visualClosed($loopContextFixture['history'][0] ?? [], $loopContextSchema['$defs']['historyEntry']['required'])
        || ! $visualClosed($loopResultFixture, $loopResultSchema['required'])
        || ($loopResultFixture['policy_version'] ?? null) !== '1.3.0'
        || ($loopResultFixture['state_digest'] ?? null) !== ($loopContextFixture['state_digest'] ?? null)
        || ($loopResultFixture['run_id'] ?? null) !== ($loopContextFixture['run_id'] ?? null)
        || ($loopResultFixture['max_evaluations'] ?? null) !== 4
        || ($loopResultFixture['max_accepted_patches'] ?? null) !== 3
        || ($loopResultFixture['minimum_score_improvement_bp'] ?? null) !== 200
        || ! $visualClosed($loopResultFixture['history_entry'] ?? [], $loopContextSchema['$defs']['historyEntry']['required'])
        || ! $visualClosed($loopResultFixture['best_so_far'] ?? [], $loopContextSchema['$defs']['historyEntry']['required'])) {
        $errors[] = 'The visual-feedback loop fixtures must distinguish four evaluations from three accepted patches and retain exact sealed-state/decision shapes.';
    }

    if (($screenshotRequestSchema['properties']['contract_version']['enum'] ?? null) !== ['1.0', '1.1', '1.2', '1.3']
        || ($screenshotRequestSchema['allOf'][0]['then']['not']['required'] ?? null) !== ['renderer']
        || ($screenshotRequestSchema['allOf'][1]['then']['required'] ?? null) !== ['renderer']
        || ($screenshotRequestSchema['allOf'][2]['then']['required'] ?? null) !== ['renderer']
        || ($screenshotRequestSchema['allOf'][3]['then']['required'] ?? null) !== ['renderer', 'capture_state']
        || ($screenshotRequestSchema['allOf'][3]['then']['not']['required'] ?? null) !== ['wait_ms']
        || ($screenshotRequestSchema['$defs']['captureState']['additionalProperties'] ?? null) !== false
        || ($screenshotRequestSchema['$defs']['captureState']['properties']['kind']['enum'] ?? null) !== ['rest', 'enter']
        || ($screenshotRequestSchema['$defs']['captureState']['properties']['target_id']['type'] ?? null) !== 'null'
        || ($screenshotResultSchema['properties']['contract_version']['enum'] ?? null) !== ['1.0', '1.1', '1.2', '1.3']
        || ($screenshotResultSchema['properties']['snapshot_id']['maxLength'] ?? null) !== 255
        || ($screenshotResultSchema['properties']['entry_path']['maxLength'] ?? null) !== 1000
        || ($screenshotResultSchema['allOf'][2]['then']['required'] ?? null) !== ['renderer', 'snapshot_id', 'entry_path']
        || ($screenshotResultSchema['allOf'][3]['then']['required'] ?? null) !== ['renderer', 'snapshot_id', 'entry_path', 'capture_state', 'capture_receipt']
        || ($screenshotResultSchema['properties']['capture_receipt']['maxLength'] ?? null) !== 4096) {
        $errors[] = 'Preview screenshot 1.3 must require a capture state and return signed Preview provenance while preserving 1.0–1.2 behavior.';
    }
    if (! $visualSchemaRoot(
        $browserEvidenceRequestSchema,
        ['contract_version', 'subject_digest', 'provider', 'format', 'quality', 'viewports'],
        ['contract_version', 'subject_digest', 'provider', 'viewports'],
    )
        || ($browserEvidenceRequestSchema['properties']['provider']['enum'] ?? null) !== ['local_puppeteer', 'cloudflare_browser_run_cdp']
        || ($browserEvidenceRequestSchema['properties']['viewports']['minItems'] ?? null) !== 3
        || ($browserEvidenceRequestSchema['properties']['viewports']['maxItems'] ?? null) !== 10
        || count($browserEvidenceRequestSchema['properties']['viewports']['allOf'] ?? []) !== 3
        || ($browserEvidenceRequestSchema['$defs']['captureState']['properties']['target_id']['type'] ?? null) !== 'null'
        || ($browserEvidenceRequestSchema['$defs']['captureState']['allOf'][0]['then']['properties']['elapsed_ms']['const'] ?? null) !== 0
        || ! $visualSchemaRoot(
            $browserEvidenceResultSchema,
            ['contract_version', 'screenshots', 'browser_evidence'],
            ['contract_version', 'screenshots', 'browser_evidence'],
        )
        || ($browserEvidenceResultSchema['properties']['screenshots']['minItems'] ?? null) !== 3
        || ($browserEvidenceResultSchema['properties']['screenshots']['items']['allOf'][0]['$ref'] ?? null) !== '../visual-feedback/review-request.json#/$defs/screenshot'
        || ($browserEvidenceResultSchema['properties']['screenshots']['items']['allOf'][1]['properties']['full_page']['const'] ?? null) !== false
        || ($browserEvidenceResultSchema['properties']['browser_evidence']['$ref'] ?? null) !== '../visual-feedback/browser-evidence.json'
        || ! $visualSchemaRoot(
            $browserEvidenceSchema,
            ['contract_version', 'profile', 'subject', 'provenance', 'viewports', 'accessibility', 'gates', 'evidence_digest', 'issued_at', 'expires_at', 'receipt'],
            ['contract_version', 'profile', 'subject', 'provenance', 'viewports', 'accessibility', 'gates', 'evidence_digest', 'issued_at', 'expires_at', 'receipt'],
        )
        || ($browserEvidenceSchema['properties']['profile']['const'] ?? null) !== 'magic-html-browser-evidence-v1'
        || ($browserEvidenceSchema['$defs']['provenance']['properties']['measurement_protocol']['const'] ?? null) !== 'chromium-devtools-protocol'
        || ($browserEvidenceSchema['$defs']['provenance']['properties']['provider']['enum'] ?? null) !== ['local_puppeteer', 'cloudflare_browser_run_cdp']
        || ($browserEvidenceSchema['$defs']['provenance']['properties']['standalone_approval']['const'] ?? null) !== false
        || ($browserEvidenceSchema['properties']['viewports']['minItems'] ?? null) !== 3
        || ($browserEvidenceSchema['properties']['viewports']['maxItems'] ?? null) !== 10
        || count($browserEvidenceSchema['$defs']['accessibilityNames']['required'] ?? []) !== 8
        || ($browserEvidenceSchema['properties']['receipt']['maxLength'] ?? null) !== 32768) {
        $errors[] = 'Preview browser evidence must be one signed same-run CDP envelope bound to 390/768/1440 rest captures, raw geometry/accessibility counters, and a non-authoritative provider provenance.';
    }
    $entryPathPattern = $reviewRequestSchema['$defs']['entryPath']['pattern'] ?? null;
    foreach (['index.html', 'pages/page-home.html', 'review/My Page.HTML'] as $acceptedEntryPath) {
        if (! is_string($entryPathPattern) || preg_match('~'.$entryPathPattern.'~D', $acceptedEntryPath) !== 1) {
            $errors[] = sprintf('Visual review entry paths must accept safe relative HTML fixture %s.', $acceptedEntryPath);
        }
    }
    foreach (['/index.html', '../index.html', 'pages/../index.html', 'pages//index.html', 'index.css'] as $rejectedEntryPath) {
        if (is_string($entryPathPattern) && preg_match('~'.$entryPathPattern.'~D', $rejectedEntryPath) === 1) {
            $errors[] = sprintf('Visual review entry paths must reject unsafe or non-HTML fixture %s.', $rejectedEntryPath);
        }
    }

    if (($reviewJobSchema['additionalProperties'] ?? null) !== false
        || ($reviewJobSchema['properties']['result']['$ref'] ?? null) !== 'review-result.json'
        || ($reviewJobSchema['properties']['status']['enum'] ?? null) !== ['queued', 'running', 'succeeded', 'failed']
        || ($pipelineJobSchema['additionalProperties'] ?? null) !== false
        || ($pipelineJobSchema['properties']['contract_version']['enum'] ?? null) !== ['1.1', '1.2', '1.3']
        || ($pipelineJobSchema['properties']['result']['$ref'] ?? null) !== 'pipeline-result.json') {
        $errors[] = 'Styler pipeline and visual-review jobs must expose bounded asynchronous envelopes with their existing result contracts.';
    }

    $expectedOpenApiOperations = [
        ['/v1/layout-snapshots', 'post', '../schemas/v1/layout/create-snapshot-request.json', '../schemas/v1/layout/create-snapshot-result.json'],
        ['/v1/layout-snapshots/{layoutSnapshot}', 'get', null, '../schemas/v1/layout/create-snapshot-result.json'],
        ['/v1/layout-snapshots/{layoutSnapshot}/patches', 'post', '../schemas/v1/layout/patch-snapshot-request.json', '../schemas/v1/layout/patch-snapshot-result.json'],
        ['/v1/layout-snapshots/{layoutSnapshot}/freeze', 'post', '../schemas/v1/layout/freeze-snapshot-request.json', '../schemas/v1/layout/freeze-snapshot-result.json'],
        ['/v1/wireframe-presentations', 'post', '../schemas/v1/wireframe/presentation-request.json', '../schemas/v1/wireframe/presentation-result.json'],
        ['/v1/previews/{preview}/layout-validations', 'post', '../schemas/v1/preview/layout-validation-request.json', '../schemas/v1/preview/layout-validation-result.json'],
        ['/v1/previews/{preview}/browser-evidence', 'post', '../schemas/v1/preview/browser-evidence-request.json', '../schemas/v1/preview/browser-evidence-result.json'],
        ['/v1/pipeline-jobs', 'post', '../schemas/v1/styler/pipeline-request.json', '../schemas/v1/styler/pipeline-job.json'],
        ['/v1/pipeline-jobs/{job}', 'get', null, '../schemas/v1/styler/pipeline-job.json'],
        ['/v1/visual-review-jobs', 'post', '../schemas/v1/visual-feedback/review-request.json', '../schemas/v1/visual-feedback/review-job.json'],
        ['/v1/visual-review-jobs/{job}', 'get', null, '../schemas/v1/visual-feedback/review-job.json'],
        ['/v1/skin-jobs/{job}/patches', 'post', '../schemas/v1/visual-feedback/patch-request.json', '../schemas/v1/visual-feedback/patch-result.json'],
        ['/v1/decor-jobs/{job}/patches', 'post', '../schemas/v1/visual-feedback/patch-request.json', '../schemas/v1/visual-feedback/patch-result.json'],
        ['/v1/motion-jobs/{job}/patches', 'post', '../schemas/v1/visual-feedback/patch-request.json', '../schemas/v1/visual-feedback/patch-result.json'],
    ];
    foreach ($expectedOpenApiOperations as [$path, $method, $requestRef, $responseRef]) {
        $operation = $tier1Schema['paths'][$path][$method] ?? [];
        $responseStatus = $method === 'post' && in_array($path, ['/v1/pipeline-jobs', '/v1/visual-review-jobs'], true)
            ? '202'
            : ($method === 'post' && str_starts_with($path, '/v1/layout-snapshots') ? '201' : '200');
        if ($operation === []
            || ($operation['security'][0]['serviceBearer'] ?? null) !== []
            || ($requestRef !== null && ($operation['requestBody']['content']['application/json']['schema']['$ref'] ?? null) !== $requestRef)
            || ($operation['responses'][$responseStatus]['content']['application/json']['schema']['$ref'] ?? null) !== $responseRef) {
            $errors[] = sprintf('Tier 1 OpenAPI must expose the authenticated visual-feedback operation %s %s with its canonical request/result schemas.', strtoupper($method), $path);
        }
    }
    $screenshotOpenApi = $tier1Schema['paths']['/v1/previews/{preview}/screenshots']['post'] ?? [];
    $reviewCaptureBoundary = $tier1Schema['paths']['/v1/visual-review-jobs']['post']['x-magic-html-capture-boundary'] ?? [];
    $browserEvidenceOpenApi = $tier1Schema['paths']['/v1/previews/{preview}/browser-evidence']['post'] ?? [];
    if (($tier1Schema['info']['version'] ?? null) !== '1.21.0'
        || ! str_contains((string) ($screenshotOpenApi['description'] ?? ''), '1.3')
        || ($screenshotOpenApi['x-magic-html-recommended-contract-version'] ?? null) !== '1.3'
        || ($screenshotOpenApi['requestBody']['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/preview/screenshot-request.json'
        || ($screenshotOpenApi['responses']['200']['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/preview/screenshot-result.json'
        || ($reviewCaptureBoundary['preview_service_review'] ?? null) !== ['rest', 'enter']
        || ($reviewCaptureBoundary['local_in_app_agent'] ?? null) !== ['rest', 'enter', 'hover', 'focus', 'active', 'open', 'expanded', 'disabled', 'reduced-motion']
        || ! is_string($reviewCaptureBoundary['cloudflare_browser_run_cdp'] ?? null)
        || ($browserEvidenceOpenApi['x-magic-html-required-rest-widths'] ?? null) !== [390, 768, 1440]
        || ($browserEvidenceOpenApi['x-magic-html-standalone-approval'] ?? null) !== false) {
        $errors[] = 'Tier 1 OpenAPI 1.21 must expose signed same-run Browser Evidence, recommend provenance-bound Preview screenshot contract 1.3, and distinguish local interactive observation from the Preview-owned Cloudflare CDP route.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect visual-feedback contracts: %s', $exception->getMessage());
}
