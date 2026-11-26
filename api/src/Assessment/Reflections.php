<?php

declare(strict_types=1);

namespace Cpdc\Assessment;

use Cpdc\Http\ApiError;

/**
 * Rating-style questions and written reflections of a self-assessment. Pure logic: the service loads and stores.
 */
final class Reflections
{
    /** The five answers of every rating (likert) question, lowest first, with their points. */
    public const LIKERT = [
        ['label' => 'Very Poor', 'points' => 1],
        ['label' => 'Poor', 'points' => 2],
        ['label' => 'Average', 'points' => 3],
        ['label' => 'Good', 'points' => 4],
        ['label' => 'Excellent', 'points' => 5],
    ];

    public const ANSWER_MAX = 2000;

    /**
     * The prompts as CPDC wrote them: trimmed, none empty, no two the same.
     *
     * @param list<string> $prompts
     * @return list<string>
     */
    public static function checkPrompts(array $prompts): array
    {
        $clean = array_map('trim', $prompts);
        foreach ($clean as $i => $prompt) {
            if ($prompt === '') {
                throw ApiError::validation("reflection_prompts.$i", 'Every reflection question needs its text.');
            }
        }
        if (count(array_unique($clean)) !== count($clean)) {
            throw ApiError::validation('reflection_prompts', 'Two reflection questions are the same.');
        }

        return $clean;
    }

    /**
     * One written answer per prompt, in order, each 1 to ANSWER_MAX characters after trimming.
     *
     * @param list<string> $prompts
     * @param list<string> $answers
     * @return list<string>|null null when the assessment has no prompts
     */
    public static function checkAnswers(array $prompts, array $answers): ?array
    {
        if ($prompts === []) {
            if ($answers !== []) {
                throw ApiError::validation('reflections', 'This assessment has no written questions.');
            }

            return null;
        }
        if (count($answers) !== count($prompts)) {
            throw ApiError::validation('reflections', 'Answer every written question.');
        }
        $clean = [];
        foreach ($answers as $i => $answer) {
            $text = trim($answer);
            if ($text === '') {
                throw ApiError::validation("reflections.$i", 'Answer every written question.');
            }
            if (mb_strlen($text) > self::ANSWER_MAX) {
                throw ApiError::validation("reflections.$i", 'Keep each written answer under 2000 characters.');
            }
            $clean[] = $text;
        }

        return $clean;
    }

    /**
     * Decodes a stored JSON list of strings; anything else counts as no list (MariaDB keeps JSON as text).
     *
     * @return list<string>
     */
    public static function decodeList(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $value = json_decode($json, true, 4);
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
