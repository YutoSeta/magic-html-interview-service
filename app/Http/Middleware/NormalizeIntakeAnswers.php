<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Yutoseta\InterviewEngine\Models\Interview;

final class NormalizeIntakeAnswers
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $interview = $request->route('interview');
        $answers = $request->input('answers');
        if (! $interview instanceof Interview || ! is_array($answers)) {
            return $next($request);
        }

        $booleanPaths = collect((array) $interview->template->fields)
            ->filter(static fn (mixed $field): bool => is_array($field)
                && ($field['type'] ?? null) === 'boolean'
                && is_string($field['path'] ?? null))
            ->pluck('path')
            ->all();

        $request->merge([
            'answers' => array_map(
                fn (mixed $answer): mixed => $this->normalizeAnswer($answer, $booleanPaths),
                $answers,
            ),
        ]);

        return $next($request);
    }

    /**
     * @param  list<string>  $booleanPaths
     */
    private function normalizeAnswer(mixed $answer, array $booleanPaths): mixed
    {
        if (! is_array($answer)
            || ! in_array($answer['path'] ?? null, $booleanPaths, true)
            || ! array_key_exists('value', $answer)) {
            return $answer;
        }

        $normalized = match ($answer['value']) {
            'true', '1', 'yes', 'はい' => true,
            'false', '0', 'no', 'いいえ' => false,
            default => $answer['value'],
        };

        return [...$answer, 'value' => $normalized];
    }
}
