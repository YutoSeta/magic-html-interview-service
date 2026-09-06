<?php

declare(strict_types=1);

/** @var string $root */
/** @var list<string> $errors */

/*
 * Neo Styler: a model writes HTML and CSS directly, inside seat belts the
 * contract makes explicit — words come from slots, links are declared as
 * {label, target, to} and resolved by the service, images are specs, and
 * fixed parts are only styled. These checks keep those belts fastened.
 */
try {
    $load = static fn (string $path): array => json_decode((string) file_get_contents($root.$path), true, flags: JSON_THROW_ON_ERROR);
    $common = $load('/schemas/v1/neo-styler/common.json');
    $siteAst = $load('/schemas/v1/neo-styler/site-ast.json');
    $content = $load('/schemas/v1/neo-styler/content.json');
    $composition = $load('/schemas/v1/neo-styler/composition.json');
    $authorRequest = $load('/schemas/v1/neo-styler/author-request.json');
    $materializeRequest = $load('/schemas/v1/neo-styler/materialize-request.json');
    $proposeRequest = $load('/schemas/v1/neo-styler/propose-request.json');
    $snapshot = $load('/schemas/v1/neo-styler/snapshot.json');
    $job = $load('/schemas/v1/neo-styler/job.json');

    $defs = $common['$defs'] ?? [];
    $linkTargets = ['form', 'section', 'external', 'tel', 'mail'];
    $forbiddenFieldNames = ['href', 'path', 'url'];
    if (($defs['linkTarget']['enum'] ?? null) !== $linkTargets
        || ($defs['link']['additionalProperties'] ?? null) !== false
        || ($defs['link']['required'] ?? null) !== ['label', 'target']
        || count($defs['link']['allOf'] ?? []) !== 5
        || ($defs['section']['propertyNames']['not']['enum'] ?? null) !== $forbiddenFieldNames
        || ($defs['item']['propertyNames']['not']['enum'] ?? null) !== $forbiddenFieldNames
        || ($defs['section']['properties']['link']['$ref'] ?? null) !== '#/$defs/link'
        || ($defs['item']['properties']['link']['$ref'] ?? null) !== '#/$defs/link'
        || ($defs['section']['properties']['images']['maxItems'] ?? null) !== 10
        || ($defs['imageSpec']['additionalProperties'] ?? null) !== false
        || ($defs['imageSpec']['required'] ?? null) !== ['aspect', 'prompt']
        || ($defs['formField']['properties']['type']['enum'] ?? null) !== ['text', 'email', 'tel', 'url', 'textarea', 'select', 'checkbox']
        || ($defs['designContext']['required'] ?? null) !== ['dna', 'direction', 'visual_references']
        || ($defs['designContext']['properties']['visual_references']['maxItems'] ?? null) !== 4
        || ($defs['designContext']['properties']['visual_references']['contains']['properties']['kind']['const'] ?? null) !== 'design-canvas'
        || ($defs['executionProfile']['enum'] ?? null) !== ['fast', 'balanced', 'quality']
        || ($defs['jobEnvelope']['properties']['operation']['enum'] ?? null) !== ['propose', 'materialize', 'author']
        || ($defs['telemetry']['required'] ?? null) !== ['provider', 'model', 'provider_request_count', 'duration_ms', 'input_tokens', 'output_tokens', 'cost_micro_usd']) {
        $errors[] = 'Neo Styler shared definitions must keep links declarative ({label, target, to} with five resolvable targets), refuse raw href/path/url fields, bound images to specs, and require direction prose plus a design canvas.';
    }

    if (($siteAst['additionalProperties'] ?? null) !== false
        || ($siteAst['properties']['version']['const'] ?? null) !== 1
        || ($siteAst['properties']['site']['properties']['type']['enum'] ?? null) !== ['site', 'landing']
        || ($siteAst['properties']['site']['properties']['cv']['properties']['href']['default'] ?? null) !== '#contact'
        || ($siteAst['$defs']['top']['properties']['widget']['enum'] ?? null) !== ['summary_nav', 'collection_summary', 'cta']
        || ($siteAst['$defs']['collection']['properties']['list_style']['enum'] ?? null) !== ['cards', 'rows']
        || ($content['additionalProperties'] ?? null) !== false
        || ($content['required'] ?? null) !== ['hero']
        || ($content['properties']['home']['properties']['sections']['items']['$ref'] ?? null) !== './common.json#/$defs/section'
        || ($content['$defs']['pageContent']['properties']['sections']['items']['$ref'] ?? null) !== './common.json#/$defs/section'
        || ($composition['required'] ?? null) !== ['hero', 'sections', 'form']
        || ($composition['properties']['hero']['required'] ?? null) !== ['title', 'lead', 'image']
        || ($composition['properties']['sections']['items']['$ref'] ?? null) !== './common.json#/$defs/section') {
        $errors[] = 'Neo Styler Site AST, content and composition must stay closed and share one section vocabulary.';
    }

    if (($authorRequest['required'] ?? null) !== ['contract_version', 'site_ast', 'content', 'design_context']
        || ($authorRequest['properties']['design_context']['$ref'] ?? null) !== './common.json#/$defs/designContext'
        || ($authorRequest['properties']['retry_limit']['maximum'] ?? null) !== 3
        || ($materializeRequest['required'] ?? null) !== ['contract_version', 'brief', 'composition']
        || ($proposeRequest['required'] ?? null) !== ['contract_version', 'brief']
        || ($snapshot['required'] ?? null) !== ['contract_version', 'site_id', 'digest', 'pages', 'image_requests', 'telemetry']
        || ($snapshot['properties']['pages']['items']['properties']['authored_by']['enum'] ?? null) !== ['model', 'expanded']
        || ($snapshot['properties']['pages']['items']['properties']['kind']['enum'] ?? null) !== ['top', 'landing', 'page', 'collection_list', 'collection_show', 'sitemap', 'system_404']
        || ($snapshot['properties']['image_requests']['items']['properties']['spec']['$ref'] ?? null) !== './common.json#/$defs/imageSpec'
        || ($job['allOf'][0]['$ref'] ?? null) !== './common.json#/$defs/jobEnvelope'
        || ($job['allOf'][1]['properties']['result']['properties']['snapshot']['$ref'] ?? null) !== './snapshot.json') {
        $errors[] = 'Neo Styler requests must be bounded, and the snapshot must report which pages a model wrote, which were expanded, and which images still have to be generated.';
    }

    // Fixtures: the belts hold on real content.
    $isKey = static fn (mixed $v): bool => is_string($v) && strlen($v) <= 40 && preg_match('/^[a-z][a-z0-9-]*$/', $v) === 1;
    $checkLink = static function (mixed $link, string $where, array $sectionKeys) use (&$errors, $linkTargets): void {
        if (! is_array($link) || ! is_string($link['label'] ?? null) || trim((string) $link['label']) === '' || ! in_array($link['target'] ?? null, $linkTargets, true)) {
            $errors[] = "{$where}: link must be {label, target, to}";

            return;
        }
        $to = $link['to'] ?? null;
        $ok = match ($link['target']) {
            'form' => $to === null,
            'section' => is_string($to) && in_array($to, $sectionKeys, true),
            'external' => is_string($to) && preg_match('#^https?://#', $to) === 1,
            'tel' => is_string($to) && preg_match('/^\+?[0-9][0-9 ()-]{5,30}$/', $to) === 1,
            'mail' => is_string($to) && filter_var($to, FILTER_VALIDATE_EMAIL) !== false,
        };
        if (! $ok) {
            $errors[] = "{$where}: link.to does not match target {$link['target']}";
        }
    };
    $checkImage = static function (mixed $image, string $where) use (&$errors): void {
        if (! is_array($image) || ! in_array($image['aspect'] ?? null, ['3:2', '2:3', '4:3', '3:4', '1:1', '16:9'], true) || ! is_string($image['prompt'] ?? null) || trim((string) $image['prompt']) === '') {
            $errors[] = "{$where}: image must be {aspect, prompt, mime?, transparent?}";
        }
    };
    $checkSections = static function (mixed $sections, string $where) use (&$errors, $isKey, $checkLink, $checkImage, $forbiddenFieldNames): array {
        $keys = [];
        foreach ((array) $sections as $i => $section) {
            if (! is_array($section) || ! $isKey($section['key'] ?? null)) {
                $errors[] = "{$where}[{$i}]: section needs a key";

                continue;
            }
            $keys[] = $section['key'];
        }
        if (count($keys) !== count(array_unique($keys))) {
            $errors[] = "{$where}: section keys must be unique";
        }
        foreach ((array) $sections as $i => $section) {
            $label = "{$where}[{$i}]";
            foreach (array_keys((array) $section) as $field) {
                if (in_array($field, $forbiddenFieldNames, true)) {
                    $errors[] = "{$label}: {$field} is not a section field; declare a link";
                }
            }
            if (isset($section['link'])) {
                $checkLink($section['link'], "{$label}.link", $keys);
            }
            if (isset($section['image'])) {
                $checkImage($section['image'], "{$label}.image");
            }
            foreach ((array) ($section['images'] ?? []) as $n => $image) {
                $checkImage($image, "{$label}.images[{$n}]");
            }
            foreach ((array) ($section['items'] ?? []) as $n => $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach (array_keys($item) as $field) {
                    if (in_array($field, $forbiddenFieldNames, true)) {
                        $errors[] = "{$label}.items[{$n}]: {$field} is not an item field; declare a link";
                    }
                }
                if (isset($item['link'])) {
                    $checkLink($item['link'], "{$label}.items[{$n}].link", $keys);
                }
                if (isset($item['image'])) {
                    $checkImage($item['image'], "{$label}.items[{$n}].image");
                }
            }
        }

        return $keys;
    };
    $checkForm = static function (mixed $form, string $where) use (&$errors): void {
        $names = [];
        foreach ((array) ($form['fields'] ?? []) as $n => $field) {
            if (! is_array($field) || ! preg_match('/^[a-z][a-z0-9_]*$/', (string) ($field['name'] ?? '')) || ! in_array($field['type'] ?? null, ['text', 'email', 'tel', 'url', 'textarea', 'select', 'checkbox'], true) || trim((string) ($field['label'] ?? '')) === '') {
                $errors[] = "{$where}.fields[{$n}]: field needs name, label and a known type";

                continue;
            }
            $names[] = $field['name'];
        }
        if ($names === [] || count($names) !== count(array_unique($names))) {
            $errors[] = "{$where}: fields must be present and unique";
        }
    };

    $siteAstFixture = $load('/tests/fixtures/neo-styler/landing-site-ast.json');
    $contentFixture = $load('/tests/fixtures/neo-styler/landing-content.json');
    $compositionFixture = $load('/tests/fixtures/neo-styler/composition.json');
    $intakeTemplate = $load('/tests/fixtures/neo-styler/landing-intake-template.json');

    if (($siteAstFixture['version'] ?? null) !== 1
        || ($siteAstFixture['site']['type'] ?? null) !== 'landing'
        || trim((string) ($siteAstFixture['site']['cv']['label'] ?? '')) === ''
        || preg_match('/^#[a-z][a-z0-9-]*$/', (string) ($siteAstFixture['site']['cv']['href'] ?? '')) !== 1
        || array_map(static fn (array $p): string => (string) $p['key'], (array) ($siteAstFixture['pages'] ?? [])) !== ['terms', 'privacy']) {
        $errors[] = 'Landing Site AST fixture must be a landing site with a CV and the two legal pages.';
    }
    if (trim((string) ($contentFixture['hero']['title'] ?? '')) === '') {
        $errors[] = 'Landing content fixture needs a hero title.';
    }
    $homeKeys = $checkSections($contentFixture['home']['sections'] ?? [], 'content.home.sections');
    if (end($homeKeys) !== 'form' || ! in_array('closing', $homeKeys, true)) {
        $errors[] = 'Landing content fixture must end with closing then the form marker section.';
    }
    $checkForm($contentFixture['home']['form'] ?? null, 'content.home.form');
    foreach ((array) ($contentFixture['pages'] ?? []) as $key => $page) {
        $checkSections($page['sections'] ?? [], "content.pages.{$key}.sections");
    }
    $subCta = (string) ($siteAstFixture['home']['sub_cta']['path'] ?? '');
    if ($subCta !== '' && ! in_array(ltrim($subCta, '#'), $homeKeys, true)) {
        $errors[] = 'Landing Site AST sub_cta must point at a content section.';
    }

    if (trim((string) ($compositionFixture['hero']['title'] ?? '')) === '' || trim((string) ($compositionFixture['hero']['lead'] ?? '')) === '') {
        $errors[] = 'Composition fixture needs a hero title and lead.';
    }
    $checkImage($compositionFixture['hero']['image'] ?? null, 'composition.hero.image');
    $compositionKeys = $checkSections($compositionFixture['sections'] ?? [], 'composition.sections');
    if (end($compositionKeys) !== 'closing') {
        $errors[] = 'Composition fixture must end with the closing section (the form follows it).';
    }
    if (isset($compositionFixture['hero']['sub_cta_section']) && ! in_array($compositionFixture['hero']['sub_cta_section'], $compositionKeys, true)) {
        $errors[] = 'Composition hero.sub_cta_section must be one of its sections.';
    }
    $checkForm($compositionFixture['form'] ?? null, 'composition.form');

    $fieldTypes = ['string', 'text', 'number', 'integer', 'boolean', 'array', 'email', 'url'];
    $paths = [];
    foreach ((array) ($intakeTemplate['fields'] ?? []) as $n => $field) {
        if (! is_array($field) || preg_match('/^[A-Za-z][A-Za-z0-9_-]*(\.[A-Za-z][A-Za-z0-9_-]*)*$/', (string) ($field['path'] ?? '')) !== 1
            || trim((string) ($field['label'] ?? '')) === '' || trim((string) ($field['question'] ?? '')) === ''
            || ! in_array($field['type'] ?? null, $fieldTypes, true) || ! is_bool($field['required'] ?? null)) {
            $errors[] = "landing-intake-template.fields[{$n}] must be a template/field.json field";

            continue;
        }
        $paths[] = $field['path'];
    }
    if (($intakeTemplate['contract_version'] ?? null) !== '1.0'
        || count($paths) !== count(array_unique($paths))
        || array_diff(['site_name', 'offer', 'cv_goal', 'cv_label', 'cv_note', 'business_name', 'tel', 'links', 'contact_email', 'notify_to'], $paths) !== []) {
        $errors[] = 'landing-intake-template fixture must be an intake-template-request with the paths materialize and propose read.';
    }

    $tier1 = $load('/openapi/tier1.json');
    $expectedOperations = [
        '/v1/design-dna-jobs' => ['post', 'createDesignDnaJob', '../schemas/v1/design-context/design-dna-request.json', '202', '../schemas/v1/design-context/job.json'],
        '/v1/design-direction-jobs' => ['post', 'createDesignDirectionJob', '../schemas/v1/design-context/design-direction-request.json', '202', '../schemas/v1/design-context/job.json'],
        '/v1/design-canvas-jobs' => ['post', 'createDesignCanvasJob', '../schemas/v1/design-context/design-canvas-request.json', '202', '../schemas/v1/design-context/job.json'],
        '/v1/decorative-asset-plan-jobs' => ['post', 'createDecorativeAssetPlanJob', '../schemas/v1/design-context/decorative-asset-plan-request.json', '202', '../schemas/v1/design-context/job.json'],
        '/v1/decorative-asset-jobs' => ['post', 'createDecorativeAssetJob', '../schemas/v1/design-context/decorative-asset-generation-request.json', '202', '../schemas/v1/design-context/job.json'],
        '/v1/propose-jobs' => ['post', 'createNeoStylerProposeJob', '../schemas/v1/neo-styler/propose-request.json', '202', '../schemas/v1/neo-styler/job.json'],
        '/v1/materializations' => ['post', 'materializeLanding', '../schemas/v1/neo-styler/materialize-request.json', '200', null],
        '/v1/author-jobs' => ['post', 'createNeoStylerAuthorJob', '../schemas/v1/neo-styler/author-request.json', '202', '../schemas/v1/neo-styler/job.json'],
    ];
    foreach ($expectedOperations as $path => [$method, $operationId, $requestRef, $status, $responseRef]) {
        $operation = $tier1['paths'][$path][$method] ?? [];
        if (($operation['operationId'] ?? null) !== $operationId
            || ($operation['security'] ?? null) !== [['serviceBearer' => []]]
            || ($operation['requestBody']['content']['application/json']['schema']['$ref'] ?? null) !== $requestRef
            || ($responseRef !== null && ($operation['responses'][$status]['content']['application/json']['schema']['$ref'] ?? null) !== $responseRef)
            || ($status === '202') !== isset($operation['parameters'])) {
            $errors[] = sprintf('Tier 1 OpenAPI must expose %s %s as an authenticated operation on its canonical request and job schemas (asynchronous ones with an Idempotency-Key, the synchronous materialization without).', strtoupper($method), $path);
        }
    }
    if (($tier1['paths']['/v1/jobs/{job}']['get']['responses']['200']['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/design-context/job.json'
        || ($tier1['paths']['/v1/neo-styler-jobs/{job}']['get']['responses']['200']['content']['application/json']['schema']['$ref'] ?? null) !== '../schemas/v1/neo-styler/job.json') {
        $errors[] = 'Tier 1 OpenAPI must expose the Design Context and Neo Styler job polling operations.';
    }
} catch (JsonException $exception) {
    $errors[] = 'Unable to inspect Neo Styler contracts: '.$exception->getMessage();
}
