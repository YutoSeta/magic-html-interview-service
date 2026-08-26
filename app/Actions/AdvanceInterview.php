<?php

namespace App\Actions;

use App\Models\InterviewSession;
use Illuminate\Support\Facades\DB;

final class AdvanceInterview
{
    /** @var list<string> */
    private const array FIELDS = ['organization', 'goals', 'audience', 'tone', 'requirements', 'materials'];

    /** @return list<array{role:string,content:string,field:string}> */
    public function initialMessages(string $locale): array
    {
        return [$this->question(0, $locale)];
    }

    public function execute(InterviewSession $session, string $answer): InterviewSession
    {
        return DB::transaction(function () use ($session, $answer): InterviewSession {
            $locked = InterviewSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status !== InterviewSession::STATUS_ACTIVE) {
                return $locked;
            }

            $step = $locked->current_step;
            $field = self::FIELDS[$step];
            $messages = $locked->messages;
            $messages[] = ['role' => 'user', 'content' => $answer, 'field' => $field];
            $structured = $locked->structured_data ?? [];
            $structured[$field] = $field === 'materials'
                ? array_values(array_filter(array_map('trim', preg_split('/\R/u', $answer) ?: [])))
                : trim($answer);
            $step++;

            $attributes = [
                'current_step' => $step,
                'messages' => $messages,
                'structured_data' => $structured,
            ];
            if ($step >= count(self::FIELDS)) {
                $attributes['status'] = InterviewSession::STATUS_COMPLETED;
            } else {
                $messages[] = $this->question($step, $locked->locale);
                $attributes['messages'] = $messages;
            }

            $locked->update($attributes);

            return $locked->refresh();
        });
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
