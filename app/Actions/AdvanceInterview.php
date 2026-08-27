<?php

namespace App\Actions;

use App\Models\InterviewSession;
use Illuminate\Support\Facades\DB;
use Yutoseta\InterviewEngine\Services\StructuredInterviewStateEngine;

final class AdvanceInterview
{
    /** @var list<string> */
    private const array FIELDS = ['organization', 'goals', 'audience', 'tone', 'requirements', 'materials'];

    public function __construct(private readonly StructuredInterviewStateEngine $stateEngine) {}

    /** @return list<array{role:string,content:string,field:string}> */
    public function initialMessages(string $locale): array
    {
        return [$this->question(0, $locale)];
    }

    public function execute(InterviewSession $session, string $answer): InterviewSession
    {
        return DB::transaction(function () use ($session, $answer): InterviewSession {
            $locked = InterviewSession::query()->lockForUpdate()->findOrFail($session->id);

            return $this->executeLocked($locked, $answer);
        });
    }

    public function executeLocked(InterviewSession $session, string $answer): InterviewSession
    {
        if ($session->status !== InterviewSession::STATUS_ACTIVE) {
            return $session;
        }

        $step = $session->current_step;
        $field = self::FIELDS[$step];
        $messages = $session->messages;
        $messages[] = ['role' => 'user', 'content' => $answer, 'field' => $field];

        $definitions = $this->fieldDefinitions($session->locale);
        $state = $this->stateFromValues($session->structured_data ?? [], $definitions);
        $result = $this->stateEngine->applyUpdates($state, $definitions, [[
            'path' => $field,
            'value' => $this->normalizeAnswer($field, $answer),
            'status' => 'confirmed',
        ]]);
        $step++;

        // The v1 HTTP contract is a scripted six-answer flow and has no
        // separate confirmation endpoint. Keep that contract while making
        // the package engine the sole authority for completeness.
        if (($result['decision']['next_action'] ?? null) === 'confirm_summary') {
            $result = $this->stateEngine->confirm($result['state'], $definitions);
        }

        $completed = ($result['decision']['next_action'] ?? null) === 'complete';
        if (! $completed && $step < count(self::FIELDS)) {
            $messages[] = $this->question($step, $session->locale);
        }

        $session->update([
            'current_step' => $step,
            'messages' => $messages,
            'structured_data' => $this->stateEngine->values($result['state']),
            'status' => $completed
                ? InterviewSession::STATUS_COMPLETED
                : InterviewSession::STATUS_ACTIVE,
        ]);

        return $session->refresh();
    }

    /**
     * @param  array<string,mixed>  $values
     * @param  list<array<string,mixed>>  $definitions
     * @return array<string,mixed>
     */
    private function stateFromValues(array $values, array $definitions): array
    {
        if ($values === []) {
            return $this->stateEngine->freshState();
        }

        $updates = [];
        foreach (self::FIELDS as $path) {
            if (array_key_exists($path, $values)) {
                $updates[] = ['path' => $path, 'value' => $values[$path], 'status' => 'confirmed'];
            }
        }

        return $this->stateEngine
            ->applyUpdates($this->stateEngine->freshState(), $definitions, $updates)['state'];
    }

    private function normalizeAnswer(string $field, string $answer): string|array
    {
        return $field === 'materials'
            ? array_values(array_filter(array_map('trim', preg_split('/\R/u', $answer) ?: [])))
            : trim($answer);
    }

    /** @return list<array<string,mixed>> */
    private function fieldDefinitions(string $locale): array
    {
        return array_map(fn (string $path, int $step): array => [
            'path' => $path,
            'label' => $path,
            'question' => $this->question($step, $locale)['content'],
            'type' => $path === 'materials' ? 'array' : 'string',
            'required' => true,
        ], self::FIELDS, array_keys(self::FIELDS));
    }

    /** @return array{role:string,content:string,field:string} */
    private function question(int $step, string $locale): array
    {
        $ja = [
            '組織・サービス名と、提供しているものを教えてください。',
            'このサイトで達成したい目的を教えてください。',
            '主な閲覧者・顧客像を教えてください。',
            '希望するトーンや印象を教えてください。',
            '必要なページ、機能、制約があれば教えてください。',
            '参考資料や既存URLがあれば、1行に1件ずつ入力してください。なければ「なし」と入力してください。',
        ];
        $en = [
            'What is the organization or service, and what does it provide?',
            'What should this website accomplish?',
            'Who is the primary audience?',
            'What tone and impression should the site convey?',
            'List required pages, functions, or constraints.',
            'List reference materials or existing URLs, one per line. Enter “none” if there are none.',
        ];

        return [
            'role' => 'assistant',
            'content' => str_starts_with(strtolower($locale), 'ja') ? $ja[$step] : $en[$step],
            'field' => self::FIELDS[$step],
        ];
    }
}
