<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;

final class UpsertIntakeTemplateRequest extends ContractRequest
{
    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        return [
            'contract_version' => ['required', 'string', 'in:1.0'],
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'description' => ['sometimes', 'string', 'max:2000'],
            'fields' => ['required', 'array', 'min:1', 'max:200'],
            'fields.*.path' => [
                'required',
                'string',
                'distinct:strict',
                'regex:/^[A-Za-z][A-Za-z0-9_-]*(?:\.[A-Za-z][A-Za-z0-9_-]*)*$/',
                'max:128',
            ],
            'fields.*.label' => ['required', 'string', 'min:1', 'max:200'],
            'fields.*.question' => ['required', 'string', 'min:1', 'max:1000'],
            'fields.*.section' => ['sometimes', 'string', 'max:200'],
            'fields.*.description' => ['sometimes', 'string', 'max:2000'],
            'fields.*.type' => [
                'required',
                'string',
                'in:string,text,number,integer,boolean,array,email,url',
            ],
            'fields.*.format' => ['sometimes', 'string', 'in:date,money'],
            'fields.*.required' => ['required', 'boolean'],
            'fields.*.example' => ['sometimes'],
            'fields.*.default' => ['sometimes'],
            'fields.*.options' => ['sometimes', 'array', 'max:100'],
            'fields.*.separator' => ['sometimes', 'string', 'max:20'],
            'mode' => ['sometimes', 'string', 'in:chat,form'],
            'greeting' => ['sometimes', 'string', 'max:1000'],
            'closing_script' => ['sometimes', 'string', 'max:1000'],
        ];
    }

    /** @return array<int,callable(Validator):void> */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                foreach ((array) $this->input('fields', []) as $index => $field) {
                    if (! is_array($field)) {
                        continue;
                    }

                    $this->rejectUnknownFieldProperties($validator, $field, $index);
                    $this->validateOptions($validator, $field, $index);
                    $this->validateSemanticFormat($validator, $field, $index);
                }
            },
        ];
    }

    /** @param array<string,mixed> $field */
    private function rejectUnknownFieldProperties(Validator $validator, array $field, int|string $index): void
    {
        $allowed = [
            'path',
            'label',
            'question',
            'section',
            'description',
            'type',
            'format',
            'required',
            'example',
            'default',
            'options',
            'separator',
        ];

        foreach (array_diff(array_keys($field), $allowed) as $property) {
            $validator->errors()->add(
                "fields.{$index}.{$property}",
                'This field property is not part of contract 1.0.',
            );
        }
    }

    /** @param array<string,mixed> $field */
    private function validateOptions(Validator $validator, array $field, int|string $index): void
    {
        foreach ((array) ($field['options'] ?? []) as $optionIndex => $option) {
            if (! is_scalar($option)) {
                $validator->errors()->add(
                    "fields.{$index}.options.{$optionIndex}",
                    'Options must contain only scalar values.',
                );
            }
        }
    }

    /** @param array<string,mixed> $field */
    private function validateSemanticFormat(Validator $validator, array $field, int|string $index): void
    {
        $format = $field['format'] ?? null;
        $type = $field['type'] ?? null;

        if ($format === 'date' && $type !== 'string') {
            $validator->errors()->add("fields.{$index}.format", 'date format requires string type.');
        }
        if ($format === 'money' && $type !== 'number') {
            $validator->errors()->add("fields.{$index}.format", 'money format requires number type.');
        }
        if (isset($field['separator']) && $type !== 'array') {
            $validator->errors()->add("fields.{$index}.separator", 'separator is only valid for array type.');
        }
    }
}
