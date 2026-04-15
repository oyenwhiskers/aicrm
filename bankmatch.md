# Bank Matching Concept Knowledge

## Purpose of this module

This module exists after the internal calculation engine.

Its job is not to calculate the applicant’s financial ability from scratch.  
Its job is to take the applicant’s processed result and determine which bank or financing provider is the best match.

In simple terms:

- the calculation engine answers:  
  **"Can this customer financially support the financing?"**

- the bank matching engine answers:  
  **"Which bank is willing and suitable to take this customer based on its rules?"**

This means bank matching is a separate decision layer and must be handled independently from the core financing calculation.

---

## Role of the bank matching module in the overall flow

The overall business flow should be:

1. lead is created
2. documents are uploaded
3. document information is extracted
4. internal financing calculation is completed
5. applicant result profile is generated
6. bank matching engine compares this result profile against bank requirements
7. system returns matched banks, failed banks, and reasons

The bank matching module should only run after the applicant profile and calculation result are already available.

---

## Core principle

The system should never ask:

**"What is the best bank in general?"**

Instead, it should ask:

**"Given this specific applicant profile and this specific financing result, which banks fit?"**

This is a rule-based matching process.

---

## What the matching engine receives as input

The bank matching engine should not work directly from raw uploaded documents.

It should receive a structured applicant result profile from earlier modules.

This profile should include the important values already derived or normalized by the system.

### Example of what the result profile should contain

- applicant sector
- employer category
- employment type
- salary / recognized income
- age
- years of service if relevant
- pensioner status
- AKPK status
- blacklist status
- CTOS / CCRIS condition
- BNPL condition
- legal / SAA condition if applicable
- financing amount requested or allowed
- calculated installment
- DSR result
- cost of living result
- deduction / repayment channel capability
- payout summary
- internal eligibility result

This result profile is the source of truth for bank matching.

---

## Two main things each bank checks

Each bank has two categories of rules.

### 1. Applicant acceptance rules
These determine whether the applicant is acceptable at all.

This includes things such as:
- whether the applicant’s sector is accepted
- whether the minimum salary is met
- whether pensioners are accepted
- whether AKPK cases are accepted
- whether blacklist cases are accepted
- whether CTOS / CCRIS conditions are accepted
- whether BNPL issues are acceptable
- whether legal / SAA conditions are acceptable

This is the first filter.

If the applicant fails a hard acceptance rule, the bank should be marked as not suitable.

---

### 2. Product and financing rules
These determine whether the calculated case fits the bank’s financing product.

This includes things such as:
- maximum financing amount
- maximum tenure
- allowed DSR
- cost of living requirement
- repayment / deduction channel requirement
- interest rate structure or pricing category
- sector-specific or deduction-specific product constraints

This is the second filter.

Even if a bank accepts the applicant profile, the product still may not fit the calculated case.

---

## Required matching logic

For each bank, the system should perform a full bank-by-bank evaluation.

### Step 1 — Check applicant acceptance
The system should compare applicant profile against the bank’s acceptance rules.

Questions the engine should answer:
- Is the applicant’s sector accepted by this bank?
- Does the applicant meet the minimum salary rule?
- Is pensioner status acceptable?
- Is AKPK status acceptable?
- Is blacklist status acceptable?
- Is CTOS / CCRIS condition acceptable?
- Is BNPL condition acceptable?
- Is legal / SAA status acceptable?

If any hard rule fails, the bank can be marked as failed immediately.

If some rule is conditional or note-based, the bank can be marked for manual review or conditional match.

---

### Step 2 — Check financing/product fit
If applicant acceptance passes, then the system should compare the calculated financing result to the bank’s product rules.

Questions the engine should answer:
- Is the financing amount within the bank’s maximum loan limit?
- Is the selected tenure within the allowed tenure?
- Is the calculated DSR within the bank’s allowed DSR?
- Does the applicant satisfy the bank’s cost-of-living rule?
- Is the repayment method compatible with this bank?
- Does the applicant fit any required salary band or channel condition for this product?

If these fail, the bank should not be matched even if the applicant profile itself is acceptable.

---

## Matching outcomes

The result for each bank should not be only yes or no.

The system should support at least these statuses:

### Matched
The applicant satisfies both:
- applicant acceptance rules
- financing/product rules

This means the case is suitable for this bank.

### Conditionally Matched
The applicant is potentially acceptable, but only if some condition is resolved.

Examples:
- must settle an outstanding amount first
- must provide supporting letter
- must clear AKPK / withdrawal evidence
- must use a specific deduction channel
- needs manual confirmation on risk note

This means the bank is possible, but not yet cleanly ready.

### Not Matched
The applicant fails one or more hard rules.

Examples:
- sector not accepted
- salary below minimum
- blacklist not accepted
- financing exceeds bank maximum
- DSR exceeds threshold

This means the case should not be routed to this bank.

### Manual Review
The rule cannot be safely decided by automation only.

Examples:
- wording in rule is conditional or exception-based
- document-based clarification needed
- note requires business judgment

This means system should not auto-route without operator review.

---

## What the PDF represents in business terms

The PDF should be understood as a bank requirement matrix.

It contains rule categories such as:

### Applicant eligibility section
This section defines which applicant types are accepted by each bank.

The observed dimensions include:
- sector
- minimum salary
- pensioner handling
- AKPK handling
- blacklist handling
- CTOS / CCRIS conditions
- legal / SAA handling
- BNPL handling

### Loan / financing product section
This section defines what financing structure each bank can support.

The observed dimensions include:
- maximum financing amount
- maximum tenure
- repayment / deduction channel
- DSR threshold
- cost of living threshold
- interest rate category

The system should treat these as structured bank rules.

---

## Bank-by-bank rule structure concept

Each bank should be represented as its own rule profile.

The system should not combine all banks into one giant formula.

Each bank must carry its own:

### Acceptance rule profile
- accepted sectors
- accepted salary floor
- pensioner policy
- AKPK policy
- blacklist policy
- CTOS / CCRIS policy
- BNPL policy
- legal / SAA policy

### Product rule profile
- max loan amount
- max tenure
- deduction channel rules
- DSR rule
- cost-of-living rule
- pricing notes
- special conditions

This makes the system maintainable and easy to update when bank policies change.

---

## Understanding of banks at a business level

The matrix suggests that banks differ in risk appetite and product constraints.

### General observed patterns

- some banks are stricter on sector
- some banks prefer government or GLC applicants
- some banks are stricter on pensioners
- some banks are stricter on blacklist and AKPK
- some banks allow certain CTOS / CCRIS conditions if settled first
- some banks have lower max loan values
- some banks are more flexible on DSR
- some banks require specific repayment channels such as Biro Angkasa, PGM, iDestinasi, or auto debit
- some banks differ by salary band or sector type

Because of this, a single applicant may:
- fail some banks
- conditionally pass some banks
- match one or two banks best

This is exactly why the matching engine is needed.

---

## Relationship between calculation engine and bank matching engine

These two modules must be treated separately.

### Calculation engine
Purpose:
- determine affordability
- determine financing amount
- determine installment
- determine payout
- determine internal case viability

### Bank matching engine
Purpose:
- determine which bank’s rules fit the applicant and financing result

The second module depends on the first module, but they are not the same thing.

A financially workable case may still fail some banks.

A weak case may still pass calculation partially but not match any bank.

---

## Recommended matching sequence

The bank matching engine should run in this order:

1. load applicant result profile
2. loop through each bank
3. check applicant acceptance rules
4. if failed, mark bank as not matched and record reasons
5. if passed, check product and financing rules
6. if failed, mark bank as not matched and record reasons
7. if partially passed with conditions, mark conditional or manual review
8. if fully passed, mark as matched
9. after checking all banks, rank or display results

This process should produce both final match result and explanation.

---

## Required explanation behavior

The system should always explain why a bank passed or failed.

This is important because operators need visibility.

### Example reasoning format

For matched bank:
- sector accepted
- salary meets requirement
- DSR within threshold
- financing amount within bank limit
- deduction channel compatible

For failed bank:
- salary below minimum
- blacklist not accepted
- financing exceeds bank maximum
- DSR above threshold

For conditional bank:
- eligible if CTOS settled first
- eligible if AKPK withdrawal letter is provided
- eligible if deduction channel changed

The system should make routing understandable, not black-box.

---

## Recommended output structure

For each applicant, the bank matching output should include:

### A. Matched banks
Banks fully suitable for submission

### B. Conditional banks
Banks that may be possible but require resolution or documents

### C. Failed banks
Banks not suitable for the case

### D. Manual review banks
Banks whose rules require human confirmation

---

## Suggested ranking logic

If multiple banks match, the system should help prioritize them.

Ranking can later consider:
- strongest rule fit
- highest financing support
- best payout result
- better repayment channel compatibility
- cleaner approval path
- lowest operational friction

At prototype stage, simple match listing is enough.  
Later, ranking can be added.

---

## Important handling principles

1. Do not match directly from raw uploaded documents  
   Always match from normalized applicant result profile

2. Do not use one universal bank formula  
   Each bank must have separate rules

3. Do not auto-approve based on one green flag  
   All relevant rules must be checked

4. Do not hide reasons  
   Every pass/fail should be explainable

5. Do not assume financially eligible means bank eligible  
   Bank rules still apply

6. Do not force binary only  
   Support matched, conditional, failed, and manual review

---

## Success criteria of this module

The bank matching module is successful if:

- the system can take a processed applicant case
- compare it against all bank rules
- determine which bank fits
- determine which bank fails
- explain why
- reduce manual routing effort
- allow operator to focus on the most suitable bank options first

---

## One-line summary

This module is a rule-based bank routing layer that takes a calculated applicant result and determines which bank products are suitable, unsuitable, or conditionally possible based on each bank’s eligibility and financing requirements.