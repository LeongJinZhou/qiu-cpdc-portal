<?php

declare(strict_types=1);

namespace Cpdc\Jobs;

use Cpdc\Http\ApiError;

/**
 * The optional detail of a job posting (slice A): specializations, vacancies, duration, pay, requirements,
 * benefits, place and a contact email. Turns a validated body into column values and a row into the API shape.
 */
final class JobDetails
{
    public const COLUMNS = ['specializations', 'vacancy_count', 'duration_months', 'salary_min', 'salary_max',
        'pay_frequency', 'min_qualification', 'work_experience', 'other_requirements', 'benefits', 'country', 'state',
        'contact_email'];
    public const PAY = ['monthly', 'hourly', 'lump_sum', 'unpaid', 'negotiable'];

    private const TEXT = ['min_qualification' => 160, 'work_experience' => 160, 'other_requirements' => 5000,
        'benefits' => 5000, 'country' => 80, 'state' => 80];

    /**
     * @param array<string, mixed> $data validated by job.save.json
     * @return array<string, string|int|null> column => value, in COLUMNS order
     */
    public static function fromBody(array $data): array
    {
        $out = array_fill_keys(self::COLUMNS, null);
        $out['specializations'] = self::specializations((string) ($data['specializations'] ?? ''));
        foreach (['vacancy_count', 'duration_months'] as $field) {
            $out[$field] = isset($data[$field]) ? (int) $data[$field] : null;
        }
        foreach (self::TEXT as $field => $max) {
            $text = trim((string) ($data[$field] ?? ''));
            $out[$field] = $text === '' ? null : mb_substr($text, 0, $max);
        }
        $pay = $data['pay_frequency'] ?? null;
        $out['pay_frequency'] = in_array($pay, self::PAY, true) ? $pay : null;
        [$out['salary_min'], $out['salary_max']] = self::salary($data, $out['pay_frequency']);
        $email = strtolower(trim((string) ($data['contact_email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ApiError::validation('contact_email', 'Enter a real email address, or leave it empty.');
        }
        $out['contact_email'] = $email === '' ? null : $email;

        return $out;
    }

    /**
     * A job row's detail columns for the API: numbers as numbers, specializations as a list.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function shape(array $row): array
    {
        $out = [];
        foreach (self::COLUMNS as $column) {
            $out[$column] = $row[$column] ?? null;
        }
        $out['specializations'] = self::split((string) ($row['specializations'] ?? ''));
        foreach (['vacancy_count', 'duration_months'] as $k) {
            $out[$k] = $out[$k] === null ? null : (int) $out[$k];
        }
        foreach (['salary_min', 'salary_max'] as $k) {
            $out[$k] = $out[$k] === null ? null : (float) $out[$k];
        }
        $out['attachment_name'] = $row['attachment_name'] ?? null;
        $out['has_brochure'] = ($row['attachment_file'] ?? null) !== null;

        return $out;
    }

    /** @return list<string> "Data Science, IT, data science" as ["Data Science", "IT"]: repeats in any case go */
    public static function split(string $text): array
    {
        $out = [];
        foreach (explode(',', $text) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[mb_strtolower($part)] ??= $part;
            }
        }

        return array_values($out);
    }

    /** Comma-separated, trimmed, without repeats; null when empty; at most 255 characters. */
    private static function specializations(string $text): ?string
    {
        $joined = implode(', ', self::split($text));
        if (mb_strlen($joined) > 255) {
            throw ApiError::validation('specializations', 'Keep the specializations under 255 characters.');
        }

        return $joined === '' ? null : $joined;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: string|null, 1: string|null} min and max as DECIMAL strings
     */
    private static function salary(array $data, ?string $pay): array
    {
        $money = static fn (mixed $v): ?string => is_int($v) || is_float($v) ? number_format((float) $v, 2, '.', '')
            : null;
        $min = $money($data['salary_min'] ?? null);
        $max = $money($data['salary_max'] ?? null);
        if ($pay === 'unpaid' && ((float) $min > 0 || (float) $max > 0)) {
            throw ApiError::validation('salary_min', 'An unpaid job has no salary. Clear the amounts.');
        }
        if (($min !== null || $max !== null) && $pay === null) {
            throw ApiError::validation('pay_frequency', 'Choose how often the salary is paid.');
        }
        if ($min !== null && $max !== null && (float) $max < (float) $min) {
            throw ApiError::validation('salary_max', 'The highest salary must be at least the lowest.');
        }

        return [$min, $max];
    }
}
