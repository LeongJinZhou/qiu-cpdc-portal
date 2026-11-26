<?php

declare(strict_types=1);

namespace Cpdc\Survey;

use Cpdc\Http\ApiError;

/**
 * The yearly University and Programme Survey: the seven sections and every data point of the legacy ACMS form, in
 * one place. The screen reads this definition from the API; the service checks every answer against it.
 * Every item is rated 1-5. Only Support Services and Facilities offers "N/A" (a student who never used the hostel
 * is never blocked).
 *
 * @phpstan-type Section array{key: string, title: string, intro: string, scale: 'agree'|'quality', na: bool,
 *     items: list<array{key: string, text: string}>, extra?: array{key: string, label: string}}
 * @phpstan-type Answers array<string, int|string>
 */
final class SurveyForm
{
    public const NA = 'na';
    public const COMMENT_MAX = 2000;

    public const SCALES = [
        'agree' => ['Strongly disagree', 'Disagree', 'Neutral', 'Agree', 'Strongly agree'],
        'quality' => ['Very Poor', 'Poor', 'Average', 'Good', 'Excellent'],
    ];

    /** @var list<Section> */
    public const SECTIONS = [
        [
            'key' => 'programme',
            'title' => 'Programme and Curriculum',
            'intro' => 'How well your programme is designed and run.',
            'scale' => 'agree',
            'na' => false,
            'items' => [
                ['key' => 'pc1', 'text' => 'The programme objectives and learning outcomes were made clear to me.'],
                ['key' => 'pc2', 'text' => 'The curriculum is relevant to what employers need today.'],
                ['key' => 'pc3', 'text' => 'The courses are well organised and build on each other.'],
                ['key' => 'pc4', 'text' => 'The workload is reasonable for the credit hours.'],
                ['key' => 'pc5', 'text' => 'The programme develops my critical thinking and problem solving.'],
                ['key' => 'pc6', 'text' => 'Practical work (labs, projects, fieldwork) supports what I learn.'],
                ['key' => 'pc7', 'text' => 'The programme prepares me for the career I want.'],
                ['key' => 'pc8', 'text' => 'Course materials and references are up to date.'],
                ['key' => 'pc9', 'text' => 'Elective courses give me useful choices.'],
                ['key' => 'pc10', 'text' => 'Overall, I am satisfied with the quality of my programme.'],
            ],
        ],
        [
            'key' => 'teaching',
            'title' => 'Teaching Staff',
            'intro' => 'The lecturers and tutors who teach your courses.',
            'scale' => 'agree',
            'na' => false,
            'items' => [
                ['key' => 'ts1', 'text' => 'Lecturers explain topics clearly.'],
                ['key' => 'ts2', 'text' => 'Lecturers come well prepared to class.'],
                ['key' => 'ts3', 'text' => 'Lecturers know their subjects well.'],
                ['key' => 'ts4', 'text' => 'Lecturers start and end classes on time.'],
                ['key' => 'ts5', 'text' => 'Lecturers encourage questions and discussion.'],
                ['key' => 'ts6', 'text' => 'Lecturers are available for consultation outside class.'],
                ['key' => 'ts7', 'text' => 'Lecturers treat students fairly and with respect.'],
                ['key' => 'ts8', 'text' => 'Lecturers use relevant examples from practice and research.'],
                ['key' => 'ts9', 'text' => 'Lecturers use the LMS and other technology well in teaching.'],
                ['key' => 'ts10', 'text' => 'Overall, I am satisfied with the teaching staff.'],
            ],
        ],
        [
            'key' => 'assessment',
            'title' => 'Assessment',
            'intro' => 'Tests, assignments, projects and how they are marked.',
            'scale' => 'agree',
            'na' => false,
            'items' => [
                ['key' => 'as1', 'text' => 'How each course is assessed and marked is explained at its start.'],
                ['key' => 'as2', 'text' => 'Assessments match what is taught.'],
                ['key' => 'as3', 'text' => 'Marking is fair and consistent.'],
                ['key' => 'as4', 'text' => 'Feedback on my work comes in time to help me improve.'],
                ['key' => 'as5', 'text' => 'The assessment workload is spread reasonably across the semester.'],
            ],
        ],
        [
            'key' => 'skills',
            'title' => 'Skills',
            'intro' => 'My studies at QIU have improved my ...',
            'scale' => 'agree',
            'na' => false,
            'items' => [
                ['key' => 'sk1', 'text' => 'Communication skills (speaking and writing).'],
                ['key' => 'sk2', 'text' => 'Teamwork.'],
                ['key' => 'sk3', 'text' => 'Critical thinking and problem solving.'],
                ['key' => 'sk4', 'text' => 'Digital and IT skills.'],
                ['key' => 'sk5', 'text' => 'Leadership.'],
                ['key' => 'sk6', 'text' => 'Ethics and professionalism.'],
                ['key' => 'sk7', 'text' => 'Entrepreneurial skills.'],
                ['key' => 'sk8', 'text' => 'Lifelong learning and finding information.'],
                ['key' => 'sk9', 'text' => 'Numeracy and working with data.'],
                ['key' => 'sk10', 'text' => 'Practical and technical skills in my field.'],
                ['key' => 'sk11', 'text' => 'Interpersonal and social skills.'],
            ],
            'extra' => [
                'key' => 'future_skills',
                'label' => 'Which skills would you like QIU to help you build in future?',
            ],
        ],
        [
            'key' => 'support',
            'title' => 'Support Services and Facilities',
            'intro' => 'Rate each one you have used. Pick N/A for any you have not used.',
            'scale' => 'quality',
            'na' => true,
            'items' => [
                ['key' => 'ss_sard', 'text' => 'SARD'],
                ['key' => 'ss_srd', 'text' => 'SRD'],
                ['key' => 'ss_sld', 'text' => 'SLD'],
                ['key' => 'ss_fad', 'text' => 'FAD'],
                ['key' => 'ss_cpdc', 'text' => 'CPDC'],
                ['key' => 'ss_ict', 'text' => 'ICT'],
                ['key' => 'ss_iso', 'text' => 'ISO'],
                ['key' => 'ss_lab', 'text' => 'Lab'],
                ['key' => 'ss_academic_support', 'text' => 'Academic Support System'],
                ['key' => 'ss_lecture_halls', 'text' => 'Lecture Halls'],
                ['key' => 'ss_hostel', 'text' => 'Hostel'],
                ['key' => 'ss_library', 'text' => 'Library'],
                ['key' => 'ss_laboratory', 'text' => 'Laboratory'],
                ['key' => 'ss_student_lounge', 'text' => 'Student Lounge'],
                ['key' => 'ss_lms', 'text' => 'LMS-eQIU'],
                ['key' => 'ss_student_portal', 'text' => 'Student Portal'],
                ['key' => 'ss_shuttle_bus', 'text' => 'Shuttle Bus'],
            ],
        ],
        [
            'key' => 'overall',
            'title' => 'Overall Experience',
            'intro' => 'Your time at QIU as a whole.',
            'scale' => 'agree',
            'na' => false,
            'items' => [
                ['key' => 'ox1', 'text' => 'I feel I belong at QIU.'],
                ['key' => 'ox2', 'text' => 'The campus is safe and well kept.'],
                ['key' => 'ox3', 'text' => 'University news and notices reach me in time.'],
                ['key' => 'ox4', 'text' => 'My concerns are listened to and acted on.'],
                ['key' => 'ox5', 'text' => 'Student activities and clubs add to my experience.'],
                ['key' => 'ox6', 'text' => 'QIU is good value for the fees I pay.'],
                ['key' => 'ox7', 'text' => 'Overall, I am satisfied with my experience at QIU.'],
            ],
        ],
        [
            'key' => 'future',
            'title' => 'Future Implication and Recommendation',
            'intro' => 'Looking ahead.',
            'scale' => 'agree',
            'na' => false,
            'items' => [
                ['key' => 'fr1', 'text' => 'I would recommend my programme to family and friends.'],
                ['key' => 'fr2', 'text' => 'I would choose QIU again if I were starting my studies now.'],
            ],
        ],
    ];

    /** @return array{sections: list<Section>, scales: array<string, list<string>>, na: string, comment_max: int} */
    public static function definition(): array
    {
        return ['sections' => self::SECTIONS, 'scales' => self::SCALES, 'na' => self::NA,
            'comment_max' => self::COMMENT_MAX];
    }

    /** @return array<string, bool> item key => N/A allowed, in form order */
    public static function items(): array
    {
        $items = [];
        foreach (self::SECTIONS as $section) {
            foreach ($section['items'] as $item) {
                $items[$item['key']] = $section['na'];
            }
        }

        return $items;
    }

    /** @return list<string> the free-text keys: one comment per section plus each section's extra question */
    public static function textKeys(): array
    {
        $keys = [];
        foreach (self::SECTIONS as $section) {
            $keys[] = $section['key'];
            if (isset($section['extra'])) {
                $keys[] = $section['extra']['key'];
            }
        }

        return $keys;
    }

    /**
     * Checks the answers against the form: known items only, 1-5, or N/A where the section offers it.
     *
     * @param array<string, mixed> $answers
     * @return Answers in form order
     */
    public static function cleanAnswers(array $answers): array
    {
        $items = self::items();
        foreach ($answers as $key => $value) {
            if (!isset($items[$key])) {
                throw ApiError::validation("answers.$key");
            }
            $na = $value === self::NA && $items[$key];
            if (!$na && (!is_int($value) || $value < 1 || $value > 5)) {
                throw ApiError::validation("answers.$key", 'Pick one of the given answers.');
            }
        }
        $clean = [];
        foreach (array_keys($items) as $key) {
            if (array_key_exists($key, $answers)) {
                $clean[$key] = $answers[$key];
            }
        }

        return $clean;
    }

    /**
     * Comments for known keys only, trimmed, empty ones left out.
     *
     * @param array<string, mixed> $comments
     * @return array<string, string>
     */
    public static function cleanComments(array $comments): array
    {
        $keys = self::textKeys();
        $clean = [];
        foreach ($comments as $key => $text) {
            if (!in_array($key, $keys, true) || !is_string($text)) {
                throw ApiError::validation("comments.$key");
            }
            $text = trim($text);
            if (mb_strlen($text) > self::COMMENT_MAX) {
                throw ApiError::validation("comments.$key", 'Keep each comment under 2000 characters.');
            }
            if ($text !== '') {
                $clean[$key] = $text;
            }
        }

        return $clean;
    }

    /**
     * @param Answers $answers
     * @return list<string> the item keys still unanswered, in form order
     */
    public static function missing(array $answers): array
    {
        return array_values(array_filter(
            array_keys(self::items()),
            static fn (string $key): bool => !array_key_exists($key, $answers)
        ));
    }

    /** @return string|null the section key an item belongs to */
    public static function sectionOf(string $itemKey): ?string
    {
        foreach (self::SECTIONS as $section) {
            foreach ($section['items'] as $item) {
                if ($item['key'] === $itemKey) {
                    return $section['key'];
                }
            }
        }

        return null;
    }
}
