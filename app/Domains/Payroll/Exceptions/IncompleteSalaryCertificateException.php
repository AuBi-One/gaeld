<?php

namespace App\Domains\Payroll\Exceptions;

use DomainException;

/**
 * A salary certificate is not issued while data it must show is missing: a
 * half-filled certificate still looks official.
 */
final class IncompleteSalaryCertificateException extends DomainException
{
    /**
     * @param  list<string>  $missing  Translated labels of the missing fields
     */
    public function __construct(public readonly array $missing)
    {
        parent::__construct(__('app.salary_certificate_incomplete', ['fields' => implode(', ', $missing)]));
    }
}
