<?php

namespace App\Enums;

enum DocumentType: string
{
    case IC = 'ic';
    case PAYSLIP = 'payslip';
    case EPF = 'epf';
    case CTOS = 'ctos';
    case RAMCI = 'ramci';
    case OTHER = 'other';

    public static function requiredChecklistItemsForPrototype(): array
    {
        return [
            ['key' => 'ic_front', 'label' => 'IC Front', 'document_type' => self::IC->value],
            ['key' => 'ic_back', 'label' => 'IC Back', 'document_type' => self::IC->value],
            ['key' => 'payslip_1', 'label' => 'Payslip Month 1', 'document_type' => self::PAYSLIP->value],
            ['key' => 'payslip_2', 'label' => 'Payslip Month 2', 'document_type' => self::PAYSLIP->value],
            ['key' => 'payslip_3', 'label' => 'Payslip Month 3', 'document_type' => self::PAYSLIP->value],
            ['key' => 'epf_year_1', 'label' => 'EPF Year 1', 'document_type' => self::EPF->value],
            ['key' => 'epf_year_2', 'label' => 'EPF Year 2', 'document_type' => self::EPF->value],
            ['key' => 'ramci', 'label' => 'RAMCI', 'document_type' => self::RAMCI->value],
            ['key' => 'ctos', 'label' => 'CTOS', 'document_type' => self::CTOS->value],
        ];
    }

    public static function allowedChecklistKeys(): array
    {
        return collect(self::requiredChecklistItemsForPrototype())
            ->pluck('key')
            ->values()
            ->all();
    }

    public static function requiredForPrototype(): array
    {
        return [
            self::IC->value => 2,
            self::PAYSLIP->value => 3,
            self::EPF->value => 2,
            self::CTOS->value => 1,
            self::RAMCI->value => 1,
        ];
    }
}