<?php

declare(strict_types=1);

/** @var string $root */
/** @var list<string> $errors */

try {
    $wireframeSchema = json_decode(
        (string) file_get_contents($root.'/schemas/v1/wireframe/ast-v2.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $validWireframe = json_decode(
        (string) file_get_contents($root.'/tests/fixtures/wireframe/ast-v2.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $invalidWireframe = json_decode(
        (string) file_get_contents($root.'/tests/fixtures/wireframe/invalid-ast-v2.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $expectedNodeReferences = array_map(
        static fn (string $name): array => ['$ref' => '#/$defs/'.$name],
        ['region', 'text', 'image', 'link', 'button', 'input', 'textarea', 'select', 'checkbox'],
    );
    if (($wireframeSchema['$schema'] ?? null) !== 'https://json-schema.org/draft/2020-12/schema'
        || ($wireframeSchema['$id'] ?? null) !== 'https://contracts.magic-html.dev/v1/wireframe/ast-v2.json'
        || ($wireframeSchema['additionalProperties'] ?? null) !== false
        || ($wireframeSchema['properties']['version']['const'] ?? null) !== 2
        || ($wireframeSchema['properties']['pages']['maxItems'] ?? null) !== 8
        || ($wireframeSchema['$defs']['documentRegion']['properties']['semantic']['const'] ?? null) !== 'document'
        || ($wireframeSchema['$defs']['documentRegion']['properties']['children']['items']['$ref'] ?? null) !== '#/$defs/documentChild'
        || ($wireframeSchema['$defs']['mainRegion']['allOf'][1]['properties']['children']['items']['$ref'] ?? null) !== '#/$defs/sectionRegion'
        || ($wireframeSchema['$defs']['region']['properties']['children']['maxItems'] ?? null) !== 40
        || ($wireframeSchema['$defs']['node']['oneOf'] ?? null) !== $expectedNodeReferences) {
        $errors[] = 'Wireframe AST v2 must remain a closed, bounded, recursive Draft 2020-12 contract with a document root and finite concrete leaves.';
    }

    $closed = static function (array $value, array $keys): bool {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    };
    $boundedText = static fn (mixed $value, int $minimum, int $maximum): bool => is_string($value)
        && strlen(trim($value)) >= $minimum
        && strlen(trim($value)) <= $maximum;
    $matchesEnum = static fn (mixed $value, array $allowed): bool => is_string($value) && in_array($value, $allowed, true);
    $matchesId = static fn (mixed $value): bool => is_string($value)
        && strlen($value) <= 100
        && preg_match('/^[a-z0-9][a-z0-9-]*$/', $value) === 1;
    $matchesFieldName = static fn (mixed $value): bool => is_string($value)
        && strlen($value) <= 100
        && preg_match('/^[a-z][a-z0-9_-]*$/', $value) === 1;
    $matchesPath = static fn (mixed $value): bool => is_string($value)
        && strlen($value) <= 200
        && preg_match('#^/(?:[a-z0-9][a-z0-9-]*(?:/[a-z0-9][a-z0-9-]*)*)?$#', $value) === 1;

    $wireframeMatches = static function (mixed $document) use (
        $boundedText,
        $closed,
        $matchesEnum,
        $matchesFieldName,
        $matchesId,
        $matchesPath,
    ): bool {
        if (! is_array($document)
            || array_is_list($document)
            || ! $closed($document, ['version', 'locale', 'pages'])
            || ($document['version'] ?? null) !== 2
            || ! is_string($document['locale'] ?? null)
            || preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $document['locale']) !== 1
            || ! is_array($document['pages'] ?? null)
            || ! array_is_list($document['pages'])
            || count($document['pages']) < 1
            || count($document['pages']) > 8) {
            return false;
        }

        $pageKeys = [];
        $pagePaths = [];
        foreach ($document['pages'] as $index => $page) {
            if (! is_array($page)
                || array_is_list($page)
                || ! $closed($page, ['key', 'path', 'title', 'root'])
                || ! $matchesId($page['key'] ?? null)
                || ! $matchesPath($page['path'] ?? null)
                || ! $boundedText($page['title'] ?? null, 1, 200)
                || isset($pageKeys[$page['key']])
                || isset($pagePaths[$page['path']])) {
                return false;
            }
            if ($index === 0 && ($page['key'] !== 'home' || $page['path'] !== '/')) {
                return false;
            }
            $pageKeys[$page['key']] = true;
            $pagePaths[$page['path']] = true;
        }

        $totalNodes = 0;
        foreach ($document['pages'] as $page) {
            $state = [
                'ids' => [],
                'anchors' => [],
                'heading_1_count' => 0,
                'main_heading_1_count' => 0,
                'section_count' => 0,
                'node_count' => 0,
            ];

            $collectFormFacts = static function (array $nodes, array &$facts) use (&$collectFormFacts): bool {
                foreach ($nodes as $node) {
                    if (($node['type'] ?? null) === 'Region') {
                        if (! $collectFormFacts($node['children'] ?? [], $facts)) {
                            return false;
                        }
                        continue;
                    }
                    if (in_array($node['type'] ?? null, ['Input', 'Textarea', 'Select', 'Checkbox'], true)) {
                        $name = $node['name'] ?? null;
                        if (! is_string($name) || isset($facts['names'][$name])) {
                            return false;
                        }
                        $facts['names'][$name] = true;
                        $facts['control_count']++;
                    }
                    if (($node['type'] ?? null) === 'Button' && ($node['button_type'] ?? null) === 'submit') {
                        $facts['submit_count']++;
                    }
                }

                return true;
            };

            $nodeMatches = static function (
                mixed $node,
                ?string $parentSemantic,
                int $depth,
                bool $insideForm,
                bool $insideMain,
            ) use (
                &$nodeMatches,
                &$state,
                $boundedText,
                $closed,
                $collectFormFacts,
                $matchesEnum,
                $matchesFieldName,
                $matchesId,
                $matchesPath,
                $pagePaths,
            ): bool {
                if (! is_array($node)
                    || array_is_list($node)
                    || $depth > 8
                    || ! $matchesId($node['id'] ?? null)
                    || isset($state['ids'][$node['id']])) {
                    return false;
                }
                $state['ids'][$node['id']] = true;
                $state['node_count']++;
                if ($state['node_count'] > 300) {
                    return false;
                }

                $type = $node['type'] ?? null;
                if ($type === 'Region') {
                    if (! $closed($node, ['type', 'id', 'semantic', 'layout', 'journey_stage', 'emphasis', 'children'])
                        || ! $matchesEnum($node['semantic'] ?? null, [
                            'document', 'header', 'navigation', 'main', 'section', 'article', 'aside', 'footer',
                            'group', 'ordered-list', 'unordered-list', 'list-item', 'form', 'field-group',
                        ])
                        || ! $matchesEnum($node['layout'] ?? null, [
                            'stack', 'cluster', 'grid-2', 'grid-3', 'grid-4', 'split', 'split-wide-start',
                            'split-wide-end', 'centered',
                        ])
                        || ! $matchesEnum($node['journey_stage'] ?? null, ['none', 'attention', 'interest', 'desire', 'memory', 'action'])
                        || ! $matchesEnum($node['emphasis'] ?? null, ['neutral', 'supporting', 'standard', 'strong', 'primary'])
                        || ! is_array($node['children'] ?? null)
                        || ! array_is_list($node['children'])
                        || count($node['children']) < 1
                        || count($node['children']) > 40
                        || ($depth === 0 && $node['semantic'] !== 'document')
                        || ($depth > 0 && $node['semantic'] === 'document')
                        || (in_array($node['semantic'], ['header', 'main', 'footer'], true) && $parentSemantic !== 'document')
                        || ($node['semantic'] === 'section' && ($parentSemantic !== 'main' || ! $insideMain))
                        || ($node['semantic'] === 'list-item' && ! in_array($parentSemantic, ['ordered-list', 'unordered-list'], true))
                        || (in_array($parentSemantic, ['ordered-list', 'unordered-list'], true) && $node['semantic'] !== 'list-item')
                        || ($node['semantic'] === 'form' && $insideForm)) {
                        return false;
                    }
                    if ($node['semantic'] === 'section') {
                        $state['section_count']++;
                    }
                    foreach ($node['children'] as $child) {
                        if ($node['semantic'] === 'main'
                            && (! is_array($child) || ($child['type'] ?? null) !== 'Region' || ($child['semantic'] ?? null) !== 'section')) {
                            return false;
                        }
                        if (! $nodeMatches(
                            $child,
                            $node['semantic'],
                            $depth + 1,
                            $insideForm || $node['semantic'] === 'form',
                            $insideMain || $node['semantic'] === 'main',
                        )) {
                            return false;
                        }
                    }
                    if ($node['semantic'] === 'form') {
                        $facts = ['control_count' => 0, 'submit_count' => 0, 'names' => []];
                        if (! $collectFormFacts($node['children'], $facts)
                            || $facts['control_count'] < 1
                            || $facts['submit_count'] !== 1) {
                            return false;
                        }
                    }

                    return true;
                }

                if (in_array($type, ['Input', 'Textarea', 'Select', 'Checkbox'], true) && ! $insideForm) {
                    return false;
                }

                if ($type === 'Text') {
                    if (! $closed($node, ['type', 'id', 'role', 'content'])
                        || ! $matchesEnum($node['role'] ?? null, [
                            'eyebrow', 'heading-1', 'heading-2', 'heading-3', 'body', 'small', 'label', 'price',
                            'step-number', 'summary',
                        ])
                        || ! $boundedText($node['content'] ?? null, 1, 8000)
                        || in_array(strtolower(trim($node['content'])), ['item', 'action', 'title', 'text', 'image', 'section'], true)) {
                        return false;
                    }
                    if ($node['role'] === 'heading-1') {
                        $state['heading_1_count']++;
                        if ($insideMain) {
                            $state['main_heading_1_count']++;
                        }
                    }

                    return true;
                }

                if ($type === 'Image') {
                    return $closed($node, ['type', 'id', 'alt', 'caption', 'aspect'])
                        && $boundedText($node['alt'] ?? null, 1, 500)
                        && (($node['caption'] ?? null) === null || $boundedText($node['caption'], 1, 1000))
                        && $matchesEnum($node['aspect'] ?? null, ['16:9', '4:3', '3:2', '1:1', '2:3']);
                }

                if ($type === 'Link') {
                    if (! $closed($node, ['type', 'id', 'label', 'href', 'emphasis'])
                        || ! $boundedText($node['label'] ?? null, 1, 300)
                        || ! $matchesEnum($node['emphasis'] ?? null, ['plain', 'secondary', 'primary'])
                        || ! is_string($node['href'] ?? null)) {
                        return false;
                    }
                    if (str_starts_with($node['href'], '#')) {
                        if (preg_match('/^#[a-z0-9][a-z0-9-]*$/', $node['href']) !== 1) {
                            return false;
                        }
                        $state['anchors'][] = substr($node['href'], 1);

                        return true;
                    }

                    return $matchesPath($node['href']) && isset($pagePaths[$node['href']]);
                }

                if ($type === 'Button') {
                    return $closed($node, ['type', 'id', 'label', 'button_type', 'emphasis'])
                        && $boundedText($node['label'] ?? null, 1, 300)
                        && $matchesEnum($node['button_type'] ?? null, ['button', 'submit', 'reset'])
                        && $matchesEnum($node['emphasis'] ?? null, ['secondary', 'primary'])
                        && (! in_array($node['button_type'], ['submit', 'reset'], true) || $insideForm);
                }

                if ($type === 'Input') {
                    return $closed($node, ['type', 'id', 'input_type', 'label', 'name', 'placeholder', 'required'])
                        && $matchesEnum($node['input_type'] ?? null, ['text', 'email', 'tel', 'url'])
                        && $boundedText($node['label'] ?? null, 1, 200)
                        && $matchesFieldName($node['name'] ?? null)
                        && (($node['placeholder'] ?? null) === null || $boundedText($node['placeholder'], 1, 300))
                        && is_bool($node['required'] ?? null);
                }

                if ($type === 'Textarea') {
                    return $closed($node, ['type', 'id', 'label', 'name', 'placeholder', 'required'])
                        && $boundedText($node['label'] ?? null, 1, 200)
                        && $matchesFieldName($node['name'] ?? null)
                        && (($node['placeholder'] ?? null) === null || $boundedText($node['placeholder'], 1, 300))
                        && is_bool($node['required'] ?? null);
                }

                if ($type === 'Select') {
                    if (! $closed($node, ['type', 'id', 'label', 'name', 'placeholder', 'required', 'options'])
                        || ! $boundedText($node['label'] ?? null, 1, 200)
                        || ! $matchesFieldName($node['name'] ?? null)
                        || (($node['placeholder'] ?? null) !== null && ! $boundedText($node['placeholder'], 1, 300))
                        || ! is_bool($node['required'] ?? null)
                        || ! is_array($node['options'] ?? null)
                        || ! array_is_list($node['options'])
                        || count($node['options']) < 1
                        || count($node['options']) > 20) {
                        return false;
                    }
                    $values = [];
                    foreach ($node['options'] as $option) {
                        if (! is_array($option)
                            || ! $closed($option, ['label', 'value'])
                            || ! $boundedText($option['label'] ?? null, 1, 200)
                            || ! $boundedText($option['value'] ?? null, 1, 100)
                            || isset($values[$option['value']])) {
                            return false;
                        }
                        $values[$option['value']] = true;
                    }

                    return true;
                }

                if ($type === 'Checkbox') {
                    return $closed($node, ['type', 'id', 'label', 'name', 'value', 'required'])
                        && $boundedText($node['label'] ?? null, 1, 300)
                        && $matchesFieldName($node['name'] ?? null)
                        && $boundedText($node['value'] ?? null, 1, 100)
                        && is_bool($node['required'] ?? null);
                }

                return false;
            };

            if (! $nodeMatches($page['root'], null, 0, false, false)) {
                return false;
            }
            $directSemantics = [];
            foreach ($page['root']['children'] as $child) {
                if (($child['type'] ?? null) !== 'Region') {
                    return false;
                }
                $directSemantics[] = $child['semantic'] ?? null;
            }
            if (array_diff($directSemantics, ['header', 'main', 'footer']) !== []
                || count(array_keys($directSemantics, 'main', true)) !== 1
                || count(array_keys($directSemantics, 'header', true)) > 1
                || count(array_keys($directSemantics, 'footer', true)) > 1
                || $state['heading_1_count'] !== 1
                || $state['main_heading_1_count'] !== 1
                || $state['section_count'] < 2
                || $state['section_count'] > 12) {
                return false;
            }
            foreach ($state['anchors'] as $anchor) {
                if (! isset($state['ids'][$anchor])) {
                    return false;
                }
            }
            $totalNodes += $state['node_count'];
            if ($totalNodes > 800) {
                return false;
            }
        }

        return true;
    };

    if (! $wireframeMatches($validWireframe) || $wireframeMatches($invalidWireframe)) {
        $errors[] = 'Wireframe AST v2 fixtures must prove the canonical semantic tree, safe local navigation, and closed form boundary.';
    }
} catch (JsonException $exception) {
    $errors[] = sprintf('Unable to inspect Wireframe AST v2 contracts: %s', $exception->getMessage());
}
