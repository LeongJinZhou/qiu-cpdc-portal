<?php

declare(strict_types=1);

namespace Cpdc\Placement;

use Cpdc\Http\ApiError;
use Cpdc\Logbook\Weeks;

/**
 * The placement form's fields: the checks the schema cannot make, and one comparable shape for a request body and
 * a database row, so a change can be stored as an old and a new snapshot.
 *
 * @phpstan-type Snapshot array<string, string|int|null>
 */
final class PlacementForm
{
    /** The columns of `internship` the form writes, in snapshot order. */
    public const FIELDS = ['company', 'business_nature', 'company_address_1', 'company_address_2', 'company_address_3',
        'company_postcode', 'company_state', 'company_country', 'start_date', 'end_date', 'batch_month', 'batch_year',
        'monthly_allowance_rm', 'roles_responsibilities', 'has_hr_contact', 'hr_name', 'hr_phone', 'hr_email',
        'supervisor_name', 'supervisor_email', 'supervisor_phone'];

    public const CHANGE_REASONS = ['company_change', 'date_change', 'supervisor_hr_update', 'allowance_role_update',
        'correction'];

    private const OPTIONAL_TEXT = ['company_address_2', 'company_address_3'];
    private const INTS = ['batch_month', 'batch_year', 'has_hr_contact'];
    private const PHONE = '/^[0-9+() -]{6,40}$/D';

    /**
     * A validated body (placement.save.json) as a snapshot, with the rules between fields checked.
     *
     * @param array<string, mixed> $body
     * @param string|null $studentEmail the student's own address, which cannot be the supervisor's
     * @return Snapshot
     */
    public static function fromBody(array $body, ?string $studentEmail): array
    {
        /** @var array<string, mixed> $out */
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $body[$field] ?? null;
            $out[$field] = is_string($value) ? trim($value) : $value;
        }
        foreach (self::OPTIONAL_TEXT as $field) {
            $out[$field] = ($out[$field] ?? '') === '' ? null : $out[$field];
        }
        if (!BusinessNature::isValid((string) $out['business_nature'])) {
            throw ApiError::validation('business_nature', 'Choose the nature of business from the list.');
        }
        foreach (['start_date', 'end_date'] as $field) {
            if (!Weeks::isDate((string) $out[$field])) {
                throw ApiError::validation($field, 'Enter a real date.');
            }
        }
        if ($out['end_date'] < $out['start_date']) {
            throw ApiError::validation('end_date', 'The end date must be on or after the start date.');
        }
        $out['supervisor_email'] = strtolower((string) $out['supervisor_email']);
        if ($studentEmail !== null && strtolower($studentEmail) === $out['supervisor_email']) {
            throw ApiError::validation('supervisor_email', 'Your supervisor needs their own email address.');
        }
        $out['monthly_allowance_rm'] = self::money($body['monthly_allowance_rm'] ?? 0);
        $out['has_hr_contact'] = ($body['has_hr_contact'] ?? false) === true ? 1 : 0;
        $out['batch_month'] = (int) $out['batch_month'];
        $out['batch_year'] = (int) $out['batch_year'];

        return self::withHr($out);
    }

    /**
     * A database row (any columns) as a snapshot of the form's fields.
     *
     * @param array<string, mixed> $row
     * @return Snapshot
     */
    public static function fromRow(array $row): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $row[$field] ?? null;
            $isInt = in_array($field, self::INTS, true);
            $out[$field] = $value === null ? null : ($isInt ? (int) $value : (string) $value);
        }
        $out['monthly_allowance_rm'] = self::money($row['monthly_allowance_rm'] ?? 0);

        return $out;
    }

    /**
     * The fields whose value differs between two snapshots.
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return list<string>
     */
    public static function changed(array $old, array $new): array
    {
        return array_values(array_filter(self::FIELDS, static fn (string $f): bool => ($old[$f] ?? null)
            !== ($new[$f] ?? null)));
    }

    /** RM with two decimals as a string ("1200.00"), the way MySQL returns a DECIMAL(10, 2). */
    private static function money(mixed $value): string
    {
        $amount = is_int($value) || is_float($value) ? (float) $value : (float) (string) $value;
        if (abs(round($amount, 2) - $amount) > 0.000001) {
            throw ApiError::validation('monthly_allowance_rm', 'Enter the allowance in RM with at most two decimals.');
        }

        return number_format($amount, 2, '.', '');
    }

    /**
     * HR contact: all three details when the company has an HR person, none when it does not.
     *
     * @param Snapshot $out
     * @return Snapshot
     */
    private static function withHr(array $out): array
    {
        if ($out['has_hr_contact'] === 0) {
            return array_merge($out, ['hr_name' => null, 'hr_phone' => null, 'hr_email' => null]);
        }
        if (mb_strlen((string) $out['hr_name']) < 2) {
            throw ApiError::validation('hr_name', "Enter the HR person's name, or tick \"No HR personnel\".");
        }
        if (preg_match(self::PHONE, (string) $out['hr_phone']) !== 1) {
            throw ApiError::validation('hr_phone', 'Enter a phone number: digits, spaces, + ( ) and - only.');
        }
        $email = strtolower((string) $out['hr_email']);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ApiError::validation('hr_email', 'Enter a real email address for HR.');
        }
        $out['hr_email'] = $email;

        return $out;
    }
}
