# Calculation Concept Knowledge — Based on Workbook Understanding

## Purpose of the calculation module

The Excel is not a simple one-step approve/reject sheet.

It is a structured financing calculator that does all of the following:

1. captures applicant profile and income context
2. converts raw deductions and CCRIS items into normalized commitments
3. calculates several financing limits using different business rules
4. picks the lowest / safest allowed financing result
5. applies bank / koperasi related charges and payout deductions
6. derives final cash outcome and monthly burden
7. determines whether the case is workable, failed, incomplete, or requires review

The CRM system should treat this as a **multi-stage calculation engine**, not a single formula.

---

## Overall business logic flow

The calculation should run in this order:

1. build applicant profile
2. calculate usable income
3. calculate current commitments
4. classify payslip deductions and CCRIS settlements
5. calculate financing eligibility from multiple independent rules
6. take the most restrictive result as the allowed financing amount
7. calculate installment for the selected financing amount
8. apply insurance / stamp duty / security deposit / settlement deductions
9. calculate net cash upon disbursement
10. calculate new monthly net income after the new facility
11. classify result

This sequence is important because later steps depend on earlier normalized values.

---

## 1. Applicant profile inputs

The workbook uses these applicant-side inputs:

- name
- NRIC
- phone number
- year / date joined
- employer / sector
- employment status
- gross income
- other income
- spouse income
- overtime handling
- existing monthly deductions
- CCRIS obligations
- financing configuration
- payment type
- bank / koperasi selection
- insurance setting
- settlement handling

### Derived profile fields

The workbook derives some profile values from inputs:

- birth year from NRIC
- current age
- years of service
- total income
- overtime treatment selection
- status-based references
- employer / sector-based rules

The system should preserve this pattern:
raw applicant data first, derived profile values second.

---

## 2. Income normalization logic

The workbook does not directly use every raw income field blindly.

It creates a normalized income base that later drives financing eligibility.

### Income-related elements observed

- monthly gross income
- other income
- spouse income
- overtime average
- total income
- basic salary

### Overtime treatment

The sheet contains selectable overtime handling using:
- overtime percentage references
- 3-month average
- 6-month average
- selected month logic

This means overtime is not always taken at full value.  
The system should model overtime as a controlled income component, not assume all overtime is fully bankable.

### Practical concept for system

The calculation engine should create these values:

- `gross_income`
- `recognized_other_income`
- `recognized_overtime`
- `total_income`
- `basic_salary`

The engine should support business rules on whether some income components are:
- fully counted
- partially counted
- ignored
- averaged across a chosen period

---

## 3. Commitment and deduction normalization

The workbook separates commitments into multiple blocks instead of one lump sum.

### A. Statutory deductions
Examples:
- EPF
- SOCSO
- tax / zakat

### B. Existing payslip deductions
This is handled as a detailed block with multiple line items.

Each line item can carry:
- deduction amount
- tenure / years
- details
- status
- settlement mode
- outstanding balance

### C. CCRIS expenses
This is another block with multiple line items.

Each line item can carry:
- financing amount
- financing type
- installment
- years
- bank
- status
- settlement mode
- balance

### D. Settlement effect
The workbook clearly distinguishes items that are:
- not settled
- settled by new loan
- settled by fund
- updated / ignored depending on status

This is important.

The CRM engine must not simply sum all commitments.  
It must first classify which commitments remain and which commitments will be cleared.

---

## 4. Payslip deduction handling concept

The workbook contains a dedicated payslip deduction detail section.

### What it appears to do

For each deduction line:
- track deduction amount
- identify whether it is loan / fund / update / no action
- classify whether settlement is by loan or by fund
- aggregate totals by settlement category

### The grouped outputs observed

The sheet produces totals similar to:
- no action total
- settle by loan total
- settle by fund total
- update total
- combined total

### Why this matters

This affects:
- current commitment burden
- amount to be settled from disbursement
- net cash payout
- post-disbursement commitment

### System concept

The system should treat every payslip deduction item as a structured object with:

- source name
- deduction amount
- tenure or years
- status
- settlement decision
- remaining balance

Then calculate:

- total existing payslip deductions
- total settled by new loan
- total settled by own fund
- total remaining after settlement
- total new payslip deduction after adding new installment

---

## 5. CCRIS handling concept

The CCRIS section is separate from payslip deductions and should remain separate in system logic.

### Workbook behavior observed

CCRIS items carry:
- financing amount
- financing type
- installment
- years
- bank
- status
- settlement method
- balance

The workbook also aggregates total CCRIS expenses and allows settlement categorization.

### Important business meaning

Not every CCRIS item should continue to burden the applicant in the same way.

Some may:
- remain active
- be ignored due to status
- be settled by the new loan
- be settled externally

### System concept

The CRM calculation engine should compute:

- `total_ccris_installment_current`
- `total_ccris_to_settle_by_loan`
- `total_ccris_to_settle_by_fund`
- `total_ccris_remaining_after_restructure`

This normalized CCRIS output then feeds the financing calculations.

---

## 6. Total commitment concept

The workbook builds total commitment from normalized components, not raw uploads.

Conceptually it behaves like:

- statutory deductions
- plus current deductions that remain relevant
- plus relevant CCRIS obligations
- minus items treated as adjusted / excluded
- plus any new required burden if applicable

The key idea is:

**total commitment must be a curated result after settlement logic, not a blind sum of all obligations**

The system should maintain both:

- `current_total_commitment`
- `projected_total_commitment_after_new_financing`

These are not the same thing.

---

## 7. Financing eligibility is calculated from multiple rules

This is the most important part of the workbook.

The sheet calculates financing eligibility using several independent methods and then consolidates them.

### Independent financing constraints observed

#### Rule A — Maximum DSR
A rule based on:
- income
- allowed debt service ratio
- current commitments
- CCRIS handling

This produces one financing limit.

#### Rule B — Cost of Living
A rule based on:
- income
- minimum residual living allowance
- current commitments

This produces another financing limit.

#### Rule C — Maximum Payslip Deduction
A rule based on:
- income
- max payslip deduction threshold
- commitments after exclusions

This produces another financing limit.

#### Rule D — Salary Multiplier
A rule based on:
- salary
- multiplier times salary
- optional bank / policy conditions

This produces another financing limit.

### Final financing logic

The workbook then takes the **minimum / most restrictive** result from these rule outputs.

This means the allowed financing amount is effectively:

- not the highest amount possible
- but the safest amount allowed by all rules

### System concept

The engine must calculate at least these intermediate outputs:

- `financing_limit_by_dsr`
- `financing_limit_by_cost_of_living`
- `financing_limit_by_payslip_deduction`
- `financing_limit_by_salary_multiplier`

Then derive:

- `final_financing_limit = minimum(valid_limits_only)`

If any rule returns invalid / failed, that should be preserved as a rule result, but final selection should only take valid candidates when appropriate.

If all fail, case should fail.

---

## 8. Financing formula pattern

The workbook uses a financing conversion pattern between:
- monthly installment capacity
- financing amount
- interest rate
- tenure

Conceptually:

1. determine how much monthly burden is allowed
2. convert that monthly affordability into a financing amount using rate and tenure
3. round results to business-friendly values

The workbook clearly rounds down / rounds up in several places.

### System concept

The engine should always work with:
- fixed rate
- tenure in years
- monthly affordability
- converted financing amount
- rounded result

It should preserve business rounding rules consistently.

---

## 9. Final selected financing amount and installment

Once the financing limit is known, the sheet derives:

- financing amount
- monthly installment
- financing eligibility indicator

This selected financing amount then becomes the base for:
- insurance
- stamp duty
- security deposit
- payout deduction rules
- net cash calculation

This means later payout calculations should never run on arbitrary numbers.  
They should run on the chosen financing amount only.

---

## 10. Insurance and age-based premium logic

The workbook contains a clear insurance table driven by:
- applicant age
- financing tenure
- insurance rate matrix

### Observed pattern

The workbook:
- derives current age
- matches age row
- matches loan year / tenure column
- retrieves insurance premium rate
- multiplies by financing amount unit basis
- produces premium value

### Important concept

Insurance is not flat.
It changes according to:
- age
- tenure
- insurance option

### System concept

The engine should support:

- applicant age lookup
- tenure lookup
- premium matrix lookup
- premium calculation
- insurance mode selection:
  - with insurance
  - no insurance

Derived values should include:
- `insurance_rate`
- `insurance_premium`
- `stamp_duty`
- optional `stamp_duty_with_insurance`

---

## 11. Security deposit logic

The workbook includes security deposit settings such as:
- no deposit
- 1 month
- 2 months
- 3 months

This indicates that payout must also consider deposit withholding rules.

### System concept

The engine should calculate:
- `security_deposit_months`
- `security_deposit_amount`

This amount is deducted from gross financing proceeds before net payout.

---

## 12. Koperasi payout deduction logic

The workbook contains koperasi-specific payout deduction tables.

Different koperasi names are handled with different payout deduction schedules.

Observed koperasi references include examples such as:
- KOSPEM
- KOBENA
- Moccis
- Kowaja

### Pattern observed

For each koperasi:
- there is a financing-amount bracket table
- each bracket maps to a deduction ratio
- the deduction ratio is applied against financing amount
- payout amount is then derived

Conceptually:
- financing amount 10k..100k
- each range has a specific deduction rate
- payout = financing amount minus payout deduction

### Important meaning

The same financing amount can lead to different payout outcomes depending on koperasi.

### System concept

The engine should:

1. detect selected koperasi
2. load koperasi-specific payout schedule
3. identify matching amount bracket
4. get payout deduction ratio
5. compute payout deduction amount
6. compute koperasi payout amount

Suggested normalized outputs:
- `selected_koperasi`
- `payout_deduction_ratio`
- `payout_deduction_amount`
- `koperasi_payout_amount`

---

## 13. Settlement by loan versus settlement by fund

The workbook repeatedly distinguishes these concepts.

### Meaning

Some existing obligations are to be cleared by:
- the new financing
- the customer’s own fund
- not settled at all

This directly affects:
- final cash released
- future monthly burden
- financing suitability

### System concept

For both payslip deductions and CCRIS items, the engine should keep separate totals:

- `settlement_by_loan_total`
- `settlement_by_fund_total`
- `remaining_commitment_total`

These totals should be reflected in:
- payout calculation
- commitment calculation
- post-disbursement cash position

---

## 14. Net cash upon disbursement

The workbook calculates a final net cash value by subtracting multiple components from the financing amount.

Observed conceptual structure:

net cash upon disbursement =
- financing amount
- minus stamp duty
- minus security deposit
- minus koperasi payout deduction / payout adjustment
- minus settlement by loan
- minus any other required upfront deductions

### System concept

The CRM should produce a final net cash summary that clearly shows the breakdown:

- gross financing amount
- insurance premium
- stamp duty
- security deposit
- settlement by loan
- koperasi payout deduction
- final net cash

This is one of the most important operational outputs.

---

## 15. New monthly net income after financing

The workbook also calculates post-financing monthly position.

Observed concept:

new monthly net income =
- total income
- minus total commitments
- minus new installment

This tells whether the applicant still has acceptable take-home balance after the new facility.

### System concept

The engine should compute:
- `monthly_net_before_new_financing`
- `new_monthly_installment`
- `monthly_net_after_new_financing`

This value should be used in final decision review and should be visible to the user.

---

## 16. Result classification concept

The workbook uses values such as:
- Failed
- Invalid
- numeric output
- blank / dash in non-applicable places

This indicates the result layer should not be binary only.

### Recommended prototype result states

- `incomplete`  
  missing required data or documents

- `invalid_input`  
  impossible or inconsistent data, such as invalid NRIC / bad reference values

- `failed_rule`  
  financing not possible because one or more required rules fail

- `conditionally_workable`  
  some outputs valid but needs manual review

- `eligible`  
  financing limit and payout logic produce workable result

### Important note

The workbook is calculation-heavy but still implies a managerial review layer.  
So final automation should produce a recommendation, not an irreversible legal decision.

---

## 17. Recommended calculation layers for system implementation

The Copilot should separate the calculation engine into these internal business stages:

### Stage 1 — Input normalization
- applicant profile
- employment info
- income values
- raw deductions
- CCRIS items
- financing setup

### Stage 2 — Derived applicant values
- age
- years of service
- recognized total income
- normalized commitments

### Stage 3 — Settlement normalization
- settle by loan totals
- settle by fund totals
- remaining deduction totals
- remaining CCRIS totals

### Stage 4 — Financing limit simulation
- limit by DSR
- limit by cost of living
- limit by payslip deduction
- limit by salary multiplier
- final selected limit

### Stage 5 — Disbursement calculation
- installment
- insurance
- stamp duty
- security deposit
- koperasi payout deduction
- settlement deduction
- final net cash

### Stage 6 — Post-financing review
- new monthly commitment
- new monthly net income
- final status
- result explanation

---

## 18. Important handling rules for prototype

The system should preserve these behavioral principles from the workbook:

1. do not calculate from raw totals only  
   always normalize first

2. do not use one financing rule only  
   evaluate multiple rule paths

3. do not choose the highest financing result  
   choose the lowest valid / safest one

4. do not mix current commitments with settled commitments  
   settlement handling must happen before final total burden

5. do not treat payout as equal to financing amount  
   payout must subtract charges, settlement, and koperasi rules

6. do not hard-approve based on one number  
   the output should carry explanation and review visibility

---

## 19. Minimum business outputs the system should show

For every processed lead, the system should generate at least:

### Applicant summary
- age
- employer / sector
- status
- gross income
- total recognized income

### Commitment summary
- statutory deductions
- existing payslip deductions
- CCRIS total
- settle by loan total
- settle by fund total
- projected post-settlement commitments

### Financing summary
- limit by DSR
- limit by cost of living
- limit by payslip deduction
- limit by multiplier
- final approved financing amount
- monthly installment

### Disbursement summary
- insurance premium
- stamp duty
- security deposit
- koperasi payout deduction
- settlement by loan amount
- final net cash upon disbursement

### Outcome summary
- new monthly net income
- final result
- reason / explanation

---

## 20. One-line interpretation of the Excel

This workbook is a **rule-based financing and payout engine** that transforms applicant income, commitments, settlement choices, and koperasi-specific payout rules into a final financing recommendation and net cash outcome.

---

## 21. Short directive for Copilot

Build the calculation module as a staged business rule engine.

The engine must:
- normalize applicant data
- normalize existing deductions and CCRIS commitments
- apply settlement logic
- simulate financing eligibility from multiple rule paths
- select the most restrictive valid financing amount
- calculate installment and post-financing burden
- apply insurance, stamp duty, security deposit, and koperasi payout deduction rules
- derive final net cash upon disbursement
- classify the result with explanation

Do not model this as a single formula.  
Model it as a multi-step calculation pipeline with clear intermediate outputs.