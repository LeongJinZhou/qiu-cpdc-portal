<?php

declare(strict_types=1);

namespace Cpdc\Survey;

/**
 * Section and item means of submitted survey answers. N/A is counted apart and never part of a mean. Pure logic.
 *
 * @phpstan-import-type Answers from SurveyForm
 */
final class SurveyStats
{
    /**
     * @param list<Answers> $responses
     * @return array{items: array<string, array{n: int, na: int, mean: float|null}>,
     *     sections: array<string, array{n: int, mean: float|null}>}
     */
    public static function summarise(array $responses): array
    {
        $items = [];
        $sections = [];
        foreach (SurveyForm::SECTIONS as $section) {
            $sectionSum = 0;
            $sectionN = 0;
            foreach ($section['items'] as $item) {
                $sum = 0;
                $n = 0;
                $na = 0;
                foreach ($responses as $answers) {
                    $value = $answers[$item['key']] ?? null;
                    if ($value === SurveyForm::NA) {
                        $na++;
                    } elseif (is_int($value)) {
                        $sum += $value;
                        $n++;
                    }
                }
                $items[$item['key']] = ['n' => $n, 'na' => $na, 'mean' => self::mean($sum, $n)];
                $sectionSum += $sum;
                $sectionN += $n;
            }
            $sections[$section['key']] = ['n' => $sectionN, 'mean' => self::mean($sectionSum, $sectionN)];
        }

        return ['items' => $items, 'sections' => $sections];
    }

    /** Submitted over invited, as a whole percent; null when nobody was invited. */
    public static function rate(int $submitted, int $invited): ?int
    {
        return $invited <= 0 ? null : (int) round($submitted * 100 / $invited);
    }

    private static function mean(int $sum, int $n): ?float
    {
        return $n === 0 ? null : round($sum / $n, 2);
    }
}
