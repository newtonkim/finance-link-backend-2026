<?php

namespace App\Tenant\Modules\Loans\Data;

/**
 * Canonical list of supported loan document type slugs and their labels.
 * Extend this list as new document types are introduced.
 */
class DocumentType
{
    public const TYPES = [
        'id_copy' => 'National ID / Passport',
        'payslip' => 'Latest Payslip',
        'bank_statement' => 'Bank Statement (3 months)',
        'employment_letter' => 'Employment / Confirmation Letter',
        'business_registration' => 'Business Registration Certificate',
        'collateral_valuation' => 'Collateral Valuation Report',
        'title_deed' => 'Title Deed',
        'logbook' => 'Vehicle Logbook',
        'guarantor_consent' => 'Guarantor Consent Form',
        'other' => 'Other',
    ];

    public static function label(string $slug): string
    {
        return self::TYPES[$slug] ?? ucwords(str_replace('_', ' ', $slug));
    }

    public static function all(): array
    {
        return array_map(
            fn ($slug, $label) => ['slug' => $slug, 'label' => $label],
            array_keys(self::TYPES),
            self::TYPES
        );
    }
}
