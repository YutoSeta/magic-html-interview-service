<?php

declare(strict_types=1);

namespace App\Actions;

use App\Support\CanonicalJson;
use App\Support\OperationResult;
use App\Support\Problem;
use Illuminate\Database\QueryException;
use Yutoseta\InterviewEngine\Models\InterviewTemplate;

final class UpsertIntakeTemplate
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function execute(string $templateKey, array $payload, string $requestId): OperationResult
    {
        $definition = $this->definition($payload);
        $existing = InterviewTemplate::query()->where('slug', $templateKey)->first();

        if ($existing !== null) {
            return $this->existingResult($existing, $definition, $requestId);
        }

        try {
            $template = InterviewTemplate::query()->create([
                'slug' => $templateKey,
                ...$definition,
            ]);
        } catch (QueryException $exception) {
            $existing = InterviewTemplate::query()->where('slug', $templateKey)->first();
            if ($existing === null) {
                throw $exception;
            }

            return $this->existingResult($existing, $definition, $requestId);
        }

        return new OperationResult(201, $this->present($template), null);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function definition(array $payload): array
    {
        return [
            'name' => $payload['title'],
            'description' => $payload['description'] ?? null,
            'fields' => array_values($payload['fields']),
            'objectives' => [],
            'persona' => [
                'mode' => $payload['mode'] ?? 'chat',
                'greeting' => $payload['greeting'] ?? '必要事項を順番に伺います。',
                'expects_artifact' => false,
            ],
            'closing_script' => $payload['closing_script'] ?? 'ご回答ありがとうございました。',
            'is_active' => true,
        ];
    }

    /** @param array<string,mixed> $definition */
    private function existingResult(
        InterviewTemplate $existing,
        array $definition,
        string $requestId,
    ): OperationResult {
        if (! hash_equals($this->definitionHash($existing), $this->hash($definition))) {
            return new OperationResult(409, Problem::body(
                409,
                'intake_template_conflict',
                'This immutable intake template key already represents another definition.',
                $requestId,
            ), null);
        }

        return new OperationResult(200, $this->present($existing), null, true);
    }

    private function definitionHash(InterviewTemplate $template): string
    {
        return $this->hash([
            'name' => $template->name,
            'description' => $template->description,
            'fields' => array_values((array) $template->fields),
            'objectives' => array_values((array) $template->objectives),
            'persona' => (array) $template->persona,
            'closing_script' => $template->closing_script,
            'is_active' => (bool) $template->is_active,
        ]);
    }

    /** @param array<string,mixed> $definition */
    private function hash(array $definition): string
    {
        return hash('sha256', CanonicalJson::encode($definition));
    }

    /** @return array<string,mixed> */
    private function present(InterviewTemplate $template): array
    {
        return [
            'contract_version' => '1.0',
            'template' => $template->slug,
            'title' => $template->name,
            'fields' => array_values((array) $template->fields),
            'mode' => (string) data_get($template->persona, 'mode', 'chat'),
        ];
    }
}
