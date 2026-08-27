<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Problem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

abstract class ContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<int,callable(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $topLevel = array_values(array_filter(
                array_keys($this->rules()),
                fn (string $key): bool => ! str_contains($key, '.'),
            ));
            foreach (array_diff(array_keys($this->all()), $topLevel) as $field) {
                $validator->errors()->add((string) $field, 'This field is not part of contract 1.0.');
            }

            if ($this->requiresIdempotency()) {
                $idempotencyKey = (string) $this->header('Idempotency-Key', '');
                if (Str::length($idempotencyKey) < 8 || Str::length($idempotencyKey) > 200) {
                    $validator->errors()->add('Idempotency-Key', 'The Idempotency-Key header must be between 8 and 200 characters.');
                }
            }
        }];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->header('Idempotency-Key');
    }

    protected function requiresIdempotency(): bool
    {
        return false;
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(Problem::response(
            $this,
            422,
            'validation_failed',
            'The request does not satisfy contract 1.0.',
            $validator->errors()->toArray(),
        ));
    }
}
