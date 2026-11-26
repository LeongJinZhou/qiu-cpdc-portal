<?php

declare(strict_types=1);

namespace Cpdc\Placement;

/**
 * The nature of a placement company's business: the 21 sections of the Malaysia Standard Industrial Classification
 * (MSIC 2008, as the old ACMS portal listed them) plus "Other". Kept here, not in an ENUM, so the list can change
 * without a migration; the server accepts only these values.
 */
final class BusinessNature
{
    public const ALL = [
        'Agriculture, forestry and fishing',
        'Mining and quarrying',
        'Manufacturing',
        'Electricity, gas, steam and air conditioning supply',
        'Water supply, sewerage and waste management',
        'Construction',
        'Wholesale and retail trade, repair of motor vehicles',
        'Transportation and storage',
        'Accommodation and food service',
        'Information and communication',
        'Financial and insurance or takaful',
        'Real estate',
        'Professional, scientific and technical',
        'Administrative and support services',
        'Public administration and defence',
        'Education',
        'Human health and social work',
        'Arts, entertainment and recreation',
        'Other service activities',
        'Households as employers',
        'Extraterritorial organisations',
        'Other',
    ];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
