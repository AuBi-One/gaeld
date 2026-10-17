<?php

namespace App\Support\Pdf;

/**
 * A Swiss VAT number with its language-appropriate suffix
 * (CHE-123.456.789 TVA / MWST / IVA / VAT), for document letterheads.
 */
final class VatNumber
{
    public static function withSuffix(?string $vatNumber, string $language): ?string
    {
        if ($vatNumber === null || trim($vatNumber) === '') {
            return null;
        }
        if (preg_match('/\b(TVA|MWST|IVA|VAT)\s*$/i', $vatNumber) === 1) {
            return $vatNumber;
        }

        return $vatNumber.' '.(['de' => 'MWST', 'it' => 'IVA', 'en' => 'VAT'][$language] ?? 'TVA');
    }
}
