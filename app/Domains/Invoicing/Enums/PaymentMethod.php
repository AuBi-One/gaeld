<?php

namespace App\Domains\Invoicing\Enums;

/**
 * How an invoice was settled. A write-off is no money received: it settles the rest of an
 * invoice nobody will pay (InvoiceAccountingService::writeOffRemainder()), so it is never
 * accepted as the method of a payment.
 */
enum PaymentMethod: string
{
    case Bank = 'bank';
    case Cash = 'cash';
    case Card = 'card';
    case WriteOff = 'write_off';

    public function label(): string
    {
        return match ($this) {
            self::Bank => __('app.payment_method_bank'),
            self::Cash => __('app.payment_method_cash'),
            self::Card => __('app.payment_method_card'),
            self::WriteOff => __('app.payment_method_write_off'),
        };
    }
}
