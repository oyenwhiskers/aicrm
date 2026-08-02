Document Classification Concept Guide

Objective

The purpose of this guide is to help the local Python document processor correctly identify each uploaded document.

Supported document types:

1. IC front
2. IC back
3. Payslip
4. EPF statement
5. RAMCI or Experian credit report
6. CTOS report
7. Other or uncertain

This phase is only about identifying the document type.

Do not perform eligibility calculations, income calculations, commitment calculations, DSR calculations, or bank matching yet.


1. General Classification Mental Model

The system should classify documents in the same way a person would inspect them.

For every document, consider these questions in order:

1. Who issued the document?
2. Why was the document created?
3. What information occupies most of the document?
4. How is the information arranged?
5. Which expected sections are present?
6. Does another document type explain the document better?

The system should understand the purpose of the whole document rather than relying on individual words.

Examples:

- An IC number can appear in a payslip, EPF statement, RAMCI report, or CTOS report.
- The word EPF can appear as one deduction inside a payslip.
- Words such as credit, loan, balance, and outstanding can appear in both RAMCI and CTOS reports.
- A person’s name can appear in every supported document.

These words may support a decision, but they should not determine the document type by themselves.


2. Keep Classification and Data Extraction Separate

The system must separate these two questions:

Question 1:
What type of document is this?

Question 2:
Can the important information inside the document be read correctly?

Example:

A document may clearly be a payslip even when the salary month cannot be read.

The correct understanding is:

- Document type is payslip
- Some required information is unreadable
- Further processing or fallback may be needed

Do not classify a clear document as other simply because one important field is missing or unreadable.


3. IC Front

Document purpose

The front of a Malaysian identity card mainly identifies the cardholder.

It normally presents the person’s main identity details in a compact card layout.

What may appear

- A portrait or photograph
- The person’s full name
- A Malaysian identity number
- Malaysia or identity-card wording
- Citizenship or identity status
- A card-shaped layout
- Personal identity information arranged around the portrait

Main concept

The IC front is the portrait-facing personal identity side of the card.

The most important concept is the combination of:

- Card-shaped layout
- Prominent portrait
- Person’s name
- Identity number
- Personal identity presentation

Strong signs

- The file clearly looks like an identity card
- A portrait is one of the main elements
- A name and identity number appear near the portrait
- The information is arranged as a personal identity card
- Several identity elements appear together

Weak signs that are not enough alone

- An identity number
- A person’s name
- The word Malaysia
- A portrait without identity-card information

How to distinguish it from other documents

A payslip, EPF statement, RAMCI report, or CTOS report may contain the same person’s name and identity number.

However, those documents do not normally use a portrait-led identity-card layout.

How to distinguish it from IC back

IC front:

- Focuses on identifying the person
- Usually contains the main portrait
- Usually presents the name and identity number prominently

IC back:

- Focuses on supporting or reverse-side card information
- Usually does not contain the main portrait presentation

Uncertain case

If the system can identify the file as an IC but cannot confidently identify the side, it should keep the document as IC and mark the side as uncertain.

Do not guess the side.


4. IC Back

Document purpose

The back of a Malaysian identity card contains supporting card information rather than the main portrait-based identity section.

What may appear

- Residential address
- Issuing authority information
- Jabatan Pendaftaran Negara wording
- Ketua Pengarah Pendaftaran Negara wording
- Signature or authority information
- Security or technical card elements
- Touch n Go wording on some card versions
- Reverse-side card layout
- No main portrait presentation

Main concept

The IC back is the non-portrait reverse side of the identity card.

Its defining concept is the combination of:

- Card-shaped reverse layout
- No main portrait section
- Supporting identity information
- Address, authority, security, or technical card content

Strong signs

- The document clearly looks like the reverse side of a card
- An address is presented as part of a card layout
- Issuing authority wording is present
- Reverse-side security or technical elements are visible
- No prominent portrait appears

Weak signs that are not enough alone

- An address
- Touch n Go wording
- Jabatan Pendaftaran Negara wording
- The absence of a photograph

These should support the decision but should not independently determine it.

Important consideration

Different versions of Malaysian identity cards may use different layouts and wording.

Do not assume every IC back must contain exactly the same text.

The system should focus on the reverse-card concept rather than one fixed template.


5. Payslip

Document purpose

A payslip is an employer-issued record explaining an employee’s pay for one salary or payroll period.

Its main purpose is to show how the employee’s payment for that period was determined.

What may appear

Employee information:

- Employee name
- Employee number
- Staff number
- Identity number
- Department
- Position or designation

Employer information:

- Company name
- Company address
- Employer details
- Payroll contact information

Payroll-period information:

- Salary month
- Pay period
- Payroll period
- Payment date
- Month and year

Earnings information:

- Basic salary
- Basic pay
- Gaji pokok
- Gross salary
- Gross pay
- Allowance
- Elaun
- Overtime
- Commission
- Bonus
- Other earnings

Deduction information:

- EPF or KWSP
- SOCSO or PERKESO
- EIS or SIP
- PCB or income tax
- Loan deduction
- Unpaid leave
- Other deductions

Payment outcome:

- Net pay
- Net salary
- Gaji bersih
- Amount credited
- Final payable amount

Main concept

A payslip connects:

- One employer
- One employee
- One payroll period
- Salary or payment components
- A final payroll outcome

A payslip does not need to contain every possible field.

Different employers may use very different formats and labels.

Strong signs

- A payslip, salary slip, slip gaji, or similar title
- Employee and employer information
- A clear payroll or salary period
- Salary components
- Payroll deductions
- Net pay or another final payment outcome
- A layout that explains one employee’s payment

Alternative valid payslip formats

Some payslips may:

- Have no deductions for that month
- Contain only a short salary summary
- Use different names for gross or net pay
- Have no formal payslip title
- Contain government payroll terminology
- Show current-month and year-to-date values
- Contain only one or two income components

The absence of one common label does not automatically mean the document is not a payslip.

How to distinguish it from an EPF statement

Payslip:

- Issued by the employer
- Focuses on one payroll period
- Explains salary, earnings, deductions, or final payment
- EPF is normally one part of the payroll deduction

EPF statement:

- Issued by or associated with KWSP
- Focuses on retirement savings, contribution history, balances, or account activity
- EPF is the main purpose of the document

How to distinguish it from other salary-related documents

The following documents should not automatically be treated as payslips:

- Employment confirmation letter
- Salary increment letter
- Offer letter
- Bank statement showing salary credit
- Payroll summary containing many employees
- Pension payment statement

A bank statement may show a salary payment, but its purpose is to report bank account transactions, not explain payroll.

Pension-related documents

If the document strongly refers to:

- Pencen
- Pesara
- Persaraan
- Slip pencen
- Penyata pencen
- Bayaran pencen
- Pension payment
- Retirement payment

Do not treat it as a normal employment payslip.

Pension documents may contain payment amounts, deductions, and net payment, but they represent pension income rather than active employment salary.

If pension slip is not currently supported, classify it as other or uncertain instead of forcing it into payslip.


6. EPF Statement

Document purpose

An EPF statement is an official member savings or contribution document issued by or associated with Kumpulan Wang Simpanan Pekerja.

Its purpose is to report the member’s EPF membership, savings, contributions, balances, or account activity.

What may appear

Official identity:

- Kumpulan Wang Simpanan Pekerja
- Employees Provident Fund
- KWSP
- EPF
- Penyata ahli
- Member statement
- Penyata caruman
- Contribution statement

Member information:

- Member name
- Member number
- Identity number
- Membership details

Employer information:

- Employer name
- Employer number
- Company information

Contribution information:

- Employee contribution
- Caruman pekerja
- Employer contribution
- Caruman majikan
- Contribution month
- Contribution date
- Wages
- Several monthly contribution records
- Annual contribution totals

Savings and account information:

- Account balances
- Opening balance
- Closing balance
- Dividends
- Withdrawals
- Adjustments
- Akaun Persaraan
- Akaun Sejahtera
- Akaun Fleksibel
- Account 1, Account 2, or other historical account names
- Akaun 55 or Akaun Emas for relevant members

Main concept

An EPF statement is mainly about the member’s retirement savings relationship with KWSP.

It may focus on:

- Contributions
- Savings balances
- Account movements
- Dividends
- Withdrawals
- Membership information

It does not always need to contain a monthly contribution table.

Strong signs

- KWSP or EPF is clearly the document authority
- The document is about a member’s savings or contribution activity
- Member and employer contribution information is present
- EPF account balances or account movements are shown
- The document covers several months, a year, or the member’s account activity

How to distinguish it from a payslip

EPF statement:

- KWSP is the authority or main document subject
- Focuses on savings accounts or contribution activity
- May cover many months or a full year
- May contain dividends, balances, or withdrawals
- Does not mainly explain one month’s salary calculation

Payslip:

- Employer is the document issuer
- Focuses on one salary period
- Shows salary earnings, deductions, or payment outcome
- EPF normally appears as one deduction or contribution item

Important prevention rule

The presence of EPF or KWSP inside a payslip does not make the document an EPF statement.

The system must identify what the whole document is mainly about.


7. RAMCI or Experian Credit Report

Document purpose

The RAMCI category represents the relevant personal credit-information report historically issued under RAMCI branding and currently associated with Experian.

This category must be confirmed against the actual reports used by the business.

Possible issuer identities

Legacy identity:

- RAMCI
- RAM Credit Information
- RAM Credit Information Sdn Bhd

Current identity:

- Experian
- Experian Information Services Malaysia
- Other verified Experian product names used in the manual process

Do not classify every Experian document as RAMCI.

Experian may issue different products, so the document must also match the expected personal credit-report purpose.

What may appear

Personal information:

- Name
- Identity number
- Address
- Borrower or applicant information

Credit information:

- Credit facilities
- Banking facilities
- Loan accounts
- Outstanding balances
- Monthly instalments
- Payment conduct
- Account status
- Repayment history
- Arrears
- Financial institution names
- Enquiry history
- Legal information
- Credit-related summaries
- Risk or score information depending on the report product

Main concept

The RAMCI or Experian document should be a formal personal credit-information report issued by Experian or historically by RAMCI.

Its purpose is to report the applicant’s credit facilities, repayment behaviour, enquiries, legal information, or related credit-risk information.

Strong signs

- Verified RAMCI or Experian issuer identity
- A clear personal credit-information report title
- Structured sections about loans, facilities, repayments, enquiries, or credit behaviour
- Several credit-report sections appearing together
- A layout consistent with known RAMCI or Experian report examples

How to distinguish it from CTOS

RAMCI and CTOS reports may contain very similar information.

Both may contain:

- Credit facilities
- Outstanding balances
- Payment behaviour
- Legal information
- Enquiries
- Personal identity details

The strongest distinction is the issuer.

RAMCI or Experian category:

- Experian or legacy RAMCI is the issuing provider
- The report matches the expected Experian or RAMCI credit-report family

CTOS category:

- CTOS is the issuing provider
- The report matches a CTOS report family

Do not decide between RAMCI and CTOS using only words such as:

- Credit
- Loan
- Facility
- Balance
- Outstanding
- Arrears

These are generic credit-report terms.

Possible uncertain case

If the file clearly appears to be a credit report but the provider cannot be identified, classify it as other or uncertain.

Do not guess RAMCI.


8. CTOS Report

Document purpose

A CTOS report is a personal or business credit-information document issued by CTOS.

Its purpose is to report credit, legal, trade, business, enquiry, or payment-related information.

Possible issuer identities

- CTOS
- CTOS Data Systems
- CTOS Data Systems Sdn Bhd
- MyCTOS
- Other verified CTOS product names

What may appear

Personal information:

- Name
- Identity number
- Address
- Personal profile

Credit-related information:

- CTOS score
- Credit facilities
- Outstanding balances
- Payment conduct
- Banking payment history
- Enquiry history
- Account status
- CCRIS-related information

Legal and business information:

- Legal cases
- Litigation
- Bankruptcy information
- Trade references
- Business interests
- Directorship information

Main concept

A CTOS report is a credit-information report whose issuing authority is CTOS.

Different CTOS products may contain different sections.

Some CTOS reports may contain a CTOS score and CCRIS information.

Other CTOS reports may focus on:

- Personal information
- Business interests
- Legal cases
- Bankruptcy
- Trade references

Do not require every CTOS report to contain:

- CTOS score
- CCRIS information
- Credit facilities
- The same fixed report sections

Strong signs

- CTOS is clearly the issuer
- The document title identifies a CTOS report
- The main purpose is credit, legal, trade, or business information
- The document contains one or more recognised CTOS report sections
- The layout matches a known CTOS report family

How to distinguish it from RAMCI or Experian

The content may be similar.

The main distinction is the issuing provider.

CTOS:

- CTOS is the document authority
- The report follows a CTOS product family

RAMCI or Experian:

- Experian or legacy RAMCI is the document authority
- The report follows the expected Experian or RAMCI credit-report family

Important consideration

One provider name may occasionally appear inside another provider’s report as a reference.

The system should identify the actual issuer using:

- Main heading
- Logo
- Report title
- Repeated page header
- Footer
- Overall branding
- Report structure

Do not classify based on one provider name appearing once inside the report body.


9. Other or Uncertain Document

Document purpose

Use other or uncertain when the system cannot confidently identify the document as one of the supported types.

This does not necessarily mean processing failed.

It means the available evidence is not strong enough to make a reliable classification.

Use other or uncertain when:

- The document is not one of the supported types
- The document purpose cannot be identified
- The issuer cannot be identified where issuer identity is important
- Several document types are equally possible
- The image is heavily cropped or incomplete
- The OCR result is too weak to understand the document
- The file contains several unrelated documents
- The file is a pension slip that should not be treated as a normal payslip
- The file is a bank statement
- The file is a loan statement
- The file is an employment letter
- The file is a financing agreement
- The file is another unsupported financial document

Do not force every document into a supported category.

A cautious uncertain result is better than a confident wrong classification.


10. Multi-Page Documents

The system should first understand the pages individually and then determine the file-level document type.

Same report across several pages

Example:

- Page 1 contains the CTOS cover page
- Page 2 contains credit information
- Page 3 contains legal information

The complete file should still be understood as one CTOS report.

Issuer branding may appear clearly only on the first page.

Later pages should be interpreted using the context of the whole report.

Mixed documents inside one file

Example:

- Page 1 is an IC
- Page 2 is a payslip
- Page 3 is an EPF statement

This is not one document.

The system should identify that several document types are combined and send the file for splitting or review.

Do not allow one strong page to incorrectly represent the whole mixed file.


11. Classification Reasoning Order

Use this reasoning order for every document:

1. Identify the likely issuer

Examples:

- Employer
- KWSP
- CTOS
- Experian or legacy RAMCI
- Malaysian identity authority

2. Understand the document purpose

Ask:

Why was this document created?

Possible purposes:

- Identify a person
- Explain one payroll payment
- Report EPF savings or contributions
- Report credit information

3. Recognise the document structure

Examples:

- Portrait-led identity card
- Reverse-side card layout
- One-period payroll statement
- Member savings or contribution statement
- Multi-section credit report

4. Review supporting information groups

Use groups of related information rather than isolated keywords.

5. Check competing document types

Ask whether another supported type explains the whole document better.

6. Classify only when the evidence is sufficient

If the evidence remains weak or conflicting, return other or uncertain.


12. Important Misclassification Prevention Rules

Identity number does not automatically mean IC

An identity number may appear in every supported document.

The document must also have an identity-card purpose and structure.

EPF wording does not automatically mean EPF statement

EPF often appears as one deduction inside a payslip.

The document must mainly be about KWSP membership, savings, or contributions.

Salary wording does not automatically mean payslip

Salary may appear in:

- Employment letters
- Bank statements
- Credit reports
- Financing forms

A payslip should represent an employer’s payroll record for one employee and one wage period.

Credit wording does not identify RAMCI or CTOS

Words such as loan, credit, facility, outstanding, and arrears are generic.

The issuing provider and report family should determine whether the document is RAMCI, Experian, or CTOS.

Pension payment should not be treated as normal salary

A pension document may contain gross payment, deductions, and net payment.

Strong pension or retiree wording should prevent classification as a normal payslip.

Unreadable fields do not change the document identity

A clear EPF statement with an unreadable year is still an EPF statement.

A clear payslip with an unreadable salary amount is still a payslip.

The field problem should be handled separately.

One page should not represent a mixed file

A file containing several document types should be identified as mixed rather than assigned to whichever page has the strongest evidence.


13. Real Document Validation

The written concepts are only the starting point.

The classification engine must be tuned using actual project documents.

Prepare verified examples of:

IC:

- Clear IC front
- Clear IC back
- Different IC versions
- Blurred IC
- Cropped IC
- IC number appearing inside another document

Payslip:

- Malay payslip
- English payslip
- Government payslip
- Private-sector payslip
- Machine-generated PDF
- Scanned payslip
- Payslip with no deduction
- Payslip containing EPF deduction
- Pension slip that must not be treated as payslip
- Bank statement showing salary credit

EPF:

- Contribution statement
- Annual member statement
- Statement containing balances and dividends
- Statement containing withdrawals
- Current and older account formats
- Payslip containing only EPF deduction

RAMCI or Experian:

- Legacy RAMCI report
- Current Experian credit report used by the business
- Different verified report products
- Generic loan document that must not be classified as RAMCI
- CTOS report that must not be classified as RAMCI

CTOS:

- CTOS basic report
- MyCTOS report
- CTOS score report
- Multi-page CTOS report
- Report without CTOS score
- RAMCI or Experian report that must not be classified as CTOS
- Generic credit document that must not be classified as CTOS

Uncertain and unsupported:

- Bank statement
- Loan agreement
- Employment letter
- Financing form
- Poor-quality image
- Mixed multi-document PDF

Whenever a classification is wrong:

1. Keep the failed document as a permanent test example
2. Identify why the document was misunderstood
3. Adjust the smallest relevant concept or rule
4. Test the change against all existing examples
5. Make sure one fix does not create a new error elsewhere


14. Final Mental Model

The classifier should not ask:

Which keywords are present?

It should ask:

1. Who issued this document?
2. Why does this document exist?
3. What type of information dominates the document?
4. How is that information structured?
5. Which supported document type best explains the complete file?
6. Is there enough evidence to make a reliable decision?

The goal is not to always return a document type.

The goal is to correctly understand the document’s purpose, distinguish similar document families, and avoid confident wrong classifications.
