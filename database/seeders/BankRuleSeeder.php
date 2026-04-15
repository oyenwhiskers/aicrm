<?php

namespace Database\Seeders;

use App\Models\Bank;
use Illuminate\Database\Seeder;

class BankRuleSeeder extends Seeder
{
    public function run(): void
    {
        $banks = [
            [
                'name' => 'Prototype Bank Prime',
                'code' => 'PBP',
                'rule' => [
                    'accepted_sectors' => ['government', 'glc', 'private'],
                    'minimum_salary' => 2500,
                    'max_loan_amount' => 60000,
                    'max_dsr' => 60,
                    'rule_notes' => ['Prototype hard-rule profile'],
                ],
            ],
            [
                'name' => 'Prototype Bank Plus',
                'code' => 'PBPX',
                'rule' => [
                    'accepted_sectors' => ['government', 'private'],
                    'minimum_salary' => 3000,
                    'max_loan_amount' => 80000,
                    'max_dsr' => 65,
                    'rule_notes' => ['Higher salary threshold, broader loan size'],
                ],
            ],
            [
                'name' => 'Prototype Bank Secure',
                'code' => 'PBS',
                'rule' => [
                    'accepted_sectors' => ['government', 'glc'],
                    'minimum_salary' => 2200,
                    'max_loan_amount' => 50000,
                    'max_dsr' => 55,
                    'rule_notes' => ['More conservative DSR profile'],
                ],
            ],
        ];

        foreach ($banks as $bankData) {
            $bank = Bank::query()->updateOrCreate(
                ['code' => $bankData['code']],
                [
                    'name' => $bankData['name'],
                    'is_active' => true,
                ]
            );

            $bank->rule()->updateOrCreate([], $bankData['rule']);
        }
    }
}