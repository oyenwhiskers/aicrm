<?php

namespace App\Enums;

enum DocumentType: string
{
    case IC = 'ic';
    case PAYSLIP = 'payslip';
    case CTOS = 'ctos';
    case RAMCI = 'ramci';
    case OTHER = 'other';

    public static function requiredForPrototype(): array
    {
        return [
            self::IC->value => 1,
            self::PAYSLIP->value => 3,
            self::CTOS->value => 1,
            self::RAMCI->value => 1,
        ];
    }
}