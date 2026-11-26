<?php

declare(strict_types=1);

namespace Cpdc\Tests\Unit;

use Cpdc\Assessment\Reflections;
use Cpdc\Http\ApiError;
use Cpdc\Http\Csv;
use Cpdc\Survey\SurveyForm;
use Cpdc\Survey\SurveyStats;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Slice B pure logic: reflections, the survey form, survey statistics, CSV formula escaping. */
final class SliceBLogicTest extends TestCase
{
    private const PROMPTS = ['How will you grow?', 'How can QIU help?'];

    public function testLikertHasFiveRatingsFromVeryPoorToExcellent(): void
    {
        $this->assertSame(
            ['Very Poor', 'Poor', 'Average', 'Good', 'Excellent'],
            array_column(Reflections::LIKERT, 'label')
        );
        $this->assertSame([1, 2, 3, 4, 5], array_column(Reflections::LIKERT, 'points'));
    }

    public function testReflectionAnswersAreTrimmedAndOnePerPrompt(): void
    {
        $this->assertSame(['Join a club.', 'Mentoring.'], Reflections::checkAnswers(self::PROMPTS, [
            '  Join a club. ', "Mentoring.\n",
        ]));
        $this->assertNull(Reflections::checkAnswers([], []), 'no prompts, nothing stored');
        $this->assertSame([str_repeat('é', 2000)], Reflections::checkAnswers(['One?'], [str_repeat('é', 2000)]));
    }

    /** @return array<string, array{list<string>, list<string>, string}> */
    public static function badReflections(): array
    {
        return [
            'one missing' => [self::PROMPTS, ['Only one'], 'reflections'],
            'one blank' => [self::PROMPTS, ['Fine', '   '], 'reflections.1'],
            'too long' => [self::PROMPTS, ['Fine', str_repeat('a', 2001)], 'reflections.1'],
            'answers without prompts' => [[], ['Extra'], 'reflections'],
            'too many' => [['One?'], ['A', 'B'], 'reflections'],
        ];
    }

    /**
     * @param list<string> $prompts
     * @param list<string> $answers
     */
    #[DataProvider('badReflections')]
    public function testMissingBlankOrLongReflectionsAreRefused(array $prompts, array $answers, string $field): void
    {
        try {
            Reflections::checkAnswers($prompts, $answers);
            $this->fail('expected a refusal');
        } catch (ApiError $e) {
            $this->assertSame([400, $field], [$e->status, $e->field]);
        }
    }

    public function testPromptsMustBeFilledAndDifferent(): void
    {
        $this->assertSame(['A?', 'B?'], Reflections::checkPrompts([' A? ', 'B?']));
        foreach ([['A?', ' '], ['A?', 'A?']] as $bad) {
            try {
                Reflections::checkPrompts($bad);
                $this->fail('expected a refusal');
            } catch (ApiError $e) {
                $this->assertSame(400, $e->status);
            }
        }
    }

    public function testStoredListsDecodeSafely(): void
    {
        $this->assertSame(['a', 'b'], Reflections::decodeList('["a", "b"]'));
        $this->assertSame([], Reflections::decodeList(null));
        $this->assertSame([], Reflections::decodeList('{"a": 1}'));
        $this->assertSame([], Reflections::decodeList('not json'));
        $this->assertSame(['a'], Reflections::decodeList('["a", 3]'));
    }

    public function testTheSurveyKeepsEverySectionAndDataPointOfTheLegacyForm(): void
    {
        $counts = array_map(static fn (array $s): int => count($s['items']), SurveyForm::SECTIONS);
        $this->assertSame([10, 10, 5, 11, 17, 7, 2], $counts);
        $this->assertSame(
            ['programme', 'teaching', 'assessment', 'skills', 'support', 'overall', 'future'],
            array_column(SurveyForm::SECTIONS, 'key')
        );
        $support = array_column(SurveyForm::SECTIONS[4]['items'], 'text');
        $this->assertSame(['SARD', 'SRD', 'SLD', 'FAD', 'CPDC', 'ICT', 'ISO', 'Lab', 'Academic Support System',
            'Lecture Halls', 'Hostel', 'Library', 'Laboratory', 'Student Lounge', 'LMS-eQIU', 'Student Portal',
            'Shuttle Bus'], $support);
        $this->assertCount(62, SurveyForm::items());
        $this->assertSame(['programme', 'teaching', 'assessment', 'skills', 'future_skills', 'support', 'overall',
            'future'], SurveyForm::textKeys());
        $keys = array_keys(SurveyForm::items());
        $this->assertSame($keys, array_unique($keys), 'item keys are unique');
    }

    public function testAnswersAreCheckedAgainstTheFormAndNaOnlyWhereOffered(): void
    {
        $clean = SurveyForm::cleanAnswers(['ss_hostel' => 'na', 'pc1' => 5]);
        $this->assertSame(['pc1' => 5, 'ss_hostel' => 'na'], $clean, 'in form order');
        $cases = [
            'unknown item' => [['zz9' => 3], 'answers.zz9'],
            'N/A outside support' => [['pc1' => 'na'], 'answers.pc1'],
            'out of range' => [['pc1' => 6], 'answers.pc1'],
            'zero' => [['ss_hostel' => 0], 'answers.ss_hostel'],
            'text' => [['pc1' => 'five'], 'answers.pc1'],
        ];
        foreach ($cases as $name => [$answers, $field]) {
            try {
                SurveyForm::cleanAnswers($answers);
                $this->fail("expected a refusal: $name");
            } catch (ApiError $e) {
                $this->assertSame($field, $e->field, $name);
            }
        }
    }

    public function testCommentsAreTrimmedEmptyOnesDroppedAndUnknownKeysRefused(): void
    {
        $this->assertSame(['skills' => 'More AI'], SurveyForm::cleanComments([
            'skills' => ' More AI ', 'programme' => '  ',
        ]));
        $this->expectException(ApiError::class);
        SurveyForm::cleanComments(['nickname' => 'x']);
    }

    public function testMissingListsUnansweredItemsInFormOrderAndNaCountsAsAnswered(): void
    {
        $all = array_map(static fn (bool $na): int|string => $na ? 'na' : 3, SurveyForm::items());
        $this->assertSame([], SurveyForm::missing($all));
        unset($all['ts4'], $all['pc2']);
        $this->assertSame(['pc2', 'ts4'], SurveyForm::missing($all));
        $this->assertSame('support', SurveyForm::sectionOf('ss_hostel'));
        $this->assertNull(SurveyForm::sectionOf('nope'));
    }

    public function testMeansLeaveNaOutAndCountItApart(): void
    {
        $stats = SurveyStats::summarise([
            ['ss_hostel' => 'na', 'ss_library' => 4, 'pc1' => 5],
            ['ss_hostel' => 2, 'ss_library' => 5, 'pc1' => 4],
            ['ss_hostel' => 'na', 'pc1' => 3],
        ]);
        $this->assertSame(['n' => 1, 'na' => 2, 'mean' => 2.0], $stats['items']['ss_hostel']);
        $this->assertSame(['n' => 2, 'na' => 0, 'mean' => 4.5], $stats['items']['ss_library']);
        $this->assertSame(['n' => 0, 'na' => 0, 'mean' => null], $stats['items']['ss_sard']);
        $this->assertSame(['n' => 3, 'mean' => 3.67], $stats['sections']['support']);
        $this->assertSame(['n' => 3, 'mean' => 4.0], $stats['sections']['programme']);
        $this->assertSame(['n' => 0, 'mean' => null], $stats['sections']['future']);
        $this->assertNull(SurveyStats::summarise([])['sections']['programme']['mean']);
    }

    public function testResponseRateIsAWholePercentAndNullWithoutInvitees(): void
    {
        $this->assertSame(67, SurveyStats::rate(2, 3));
        $this->assertSame(0, SurveyStats::rate(0, 5));
        $this->assertNull(SurveyStats::rate(3, 0));
    }

    public function testCsvEscapesFormulaStartsIncludingTheFullWidthAt(): void
    {
        $body = Csv::response('x.csv', ['a'], [['=1+1'], ['+1'], ['-1'], ['@SUM'], ["\u{FF20}SUM"], ["\tx"], ['ok']])
            ->body;
        foreach (["\"'=1+1\"", "\"'+1\"", "\"'-1\"", "\"'@SUM\"", "\"'\u{FF20}SUM\"", "\"'\tx\"", '"ok"'] as $cell) {
            $this->assertStringContainsString($cell, $body);
        }
    }
}
