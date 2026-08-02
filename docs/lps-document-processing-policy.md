LPS Document Processing Policy

Purpose

This document explains what the system should do after the Python processor identifies a document.

The document classification guide answers:

What type of document is this?

This processing policy answers:

1. Is the classification reliable enough to accept?
2. Are the important fields available?
3. Can the document be used by the current checklist workflow?
4. Should Gemini be used as fallback?
5. Should the document be sent for manual review?

This phase does not cover:

- Loan eligibility calculations
- DSR calculations
- Income qualification
- Commitment calculations
- Bank policy matching
- Loan recommendations


1. Supported Document Types

The supported top-level document types are:

- ic
- payslip
- pension_slip
- epf
- ramci
- ctos
- other

For IC documents, the side is stored separately:

- front
- back
- uncertain

IC front and IC back are not separate top-level document types.

The correct understanding is:

- document type is ic
- IC side is front or back


2. Main Processing Outcomes

The system should not treat every result as simply success or failure.

The main processing outcomes should be understood as follows.


Accepted and ready

The document type is reliable.

The important fields required by the current workflow are available.

The document can proceed to storage, normalization, and checklist assignment.


Accepted but incomplete

The document type is reliable, but one or more important fields are missing.

Example:

- The document is clearly a payslip
- The salary period cannot be read

The document should remain classified as payslip.

The system may use Gemini to recover the missing fields.

Do not change the document type to other simply because a field is unreadable.


Needs Gemini fallback

The local Python result is not sufficient.

This may happen because:

- Classification is uncertain
- Important fields are missing
- OCR quality is poor
- Strong signals conflict with each other
- The issuer cannot be confirmed
- The file structure is unusual

Gemini should be used only for these cases.


Needs manual review

A human needs to inspect the document.

This may happen because:

- Python and Gemini both fail
- Gemini is unavailable or quota-limited
- Several documents are combined in one file
- The document appears incomplete or heavily cropped
- The result remains conflicting after fallback
- The document is readable but does not match a supported workflow
- The user-provided type conflicts strongly with the detected type


Unsupported document

The document is readable, but it is not one of the supported types.

Examples:

- Bank statement
- Employment letter
- Loan agreement
- Salary increment letter
- Financing agreement
- Offer letter

The correct document type is other.

An unsupported document should not automatically be treated as an OCR failure.


Technical processing failure

The system could not process the file technically.

Examples:

- Corrupted PDF
- Password-protected PDF
- Unsupported file format
- OCR engine failure
- Python service timeout
- File cannot be opened

A technical failure is different from an unsupported document or uncertain classification.


3. General Acceptance Principle

Python should not be accepted only because it returns high confidence.

Laravel should consider:

1. Whether the document identity is clear
2. Whether the expected document structure exists
3. Whether critical fields are available
4. Whether any strong conflicting signals exist
5. Whether the result is suitable for the current checklist workflow

A local result should be accepted only when the complete evidence is reasonable.

Confidence is supporting information, not the only decision factor.


4. IC Processing Policy

Document type

- ic

Required side value

- front
- back

The side should not remain uncertain for checklist assignment.


IC front critical information

Useful identity fields include:

- Full name
- IC number
- Clear front-side identity structure
- Portrait-led card layout

For current local acceptance, the system should normally have:

- A confident IC classification
- A confident front-side classification
- At least one reliable identity field, preferably the IC number or full name
- No stronger evidence that the image is actually the back side


IC front accepted and ready

Accept locally when:

- The document clearly appears to be an IC
- The side is clearly front
- The front-side structure is visible
- The person’s identity information is at least partly readable
- No major conflicting signals exist


IC front needs Gemini fallback

Use Gemini when:

- The document appears to be an IC but the side is unclear
- The front is predicted but both the full name and IC number are unreadable
- The image is blurred, reflected, rotated, or heavily compressed
- The portrait and identity layout are partly cropped
- Front and back signals are too close


IC front needs manual review

Use manual review when:

- Python and Gemini cannot determine the side
- The image contains more than one card
- The IC is severely cropped
- The document appears altered or incomplete
- The user selected IC front but the system strongly detects IC back


IC back critical information

Useful back-side evidence includes:

- Reverse-card structure
- Address block
- Issuing authority information
- Security or technical card elements
- No main portrait presentation

Not every IC version will contain the same wording.

The system should not require one exact phrase such as Touch n Go or Ketua Pengarah Pendaftaran Negara.


IC back accepted and ready

Accept locally when:

- The document clearly appears to be an IC
- The side is clearly back
- Several reverse-side characteristics are visible
- There is no strong portrait-led front-side structure


IC back needs Gemini fallback

Use Gemini when:

- The document appears to be an IC but the side is unclear
- The back is predicted but almost no back-side information survives OCR
- The card is heavily cropped
- Front and back evidence conflicts
- The image quality is too poor to confirm the reverse-side structure


IC checklist rule

Only a confirmed IC front should fill the IC front checklist slot.

Only a confirmed IC back should fill the IC back checklist slot.

An IC with an uncertain side should not automatically fill either slot.


5. Payslip Processing Policy

Document type

- payslip


Critical information

For the current checklist workflow, the most important information is:

- Statement period
- Statement month
- Statement year

Useful salary information includes:

- Basic salary
- Gross income
- Net pay
- Total deductions
- Employer
- Employee name

For local workflow acceptance, the system should normally have:

- A confident payslip classification
- A valid statement period
- At least one meaningful salary amount
- No strong pension conflict


Payslip accepted and ready

Accept locally when:

- The document clearly represents one employee’s payroll record
- The statement period is available and valid
- At least one of the following is available:
  - Basic salary
  - Gross income
  - Net pay
- No strong pension indicators are present
- No stronger EPF-statement identity exists
- The document does not appear to be a bank statement or employment letter


Payslip accepted but incomplete

The document may still be classified as payslip when:

- The payslip identity is clear
- Some salary fields are unreadable
- Employer information is missing
- One deduction section is unclear

However, if the statement period is missing, the document cannot be used for automatic payslip checklist assignment.


Payslip needs Gemini fallback

Use Gemini when:

- Statement period is missing or unclear
- Both basic salary and gross income are missing
- The document identity is likely payslip but the payroll layout is unusual
- Salary and pension indicators conflict
- OCR text is too weak to identify the payroll period
- The result conflicts with the user-provided document type


Payslip needs manual review

Use manual review when:

- Python and Gemini cannot determine the statement period
- The document combines several employees
- Several payslips are scanned into one file without clear separation
- The document appears to be an employer payroll summary rather than an individual payslip
- Important extracted amounts conflict significantly
- The file is incomplete or heavily cropped


Payslip checklist rule

A payslip can only participate in the automatic three-month payslip checklist when:

- Document type is payslip
- It does not need review
- Statement period is available
- The statement period is valid

The existing consecutive three-month checklist rules remain controlled by Laravel.


6. Pension Slip Processing Policy

Document type

- pension_slip

Pension slip is a supported type.

It must not be classified as other merely because it resembles a payslip.


Critical information

Useful pension information includes:

- Clear pension or retiree identity
- Statement period or payment period
- Pension recipient name
- Gross pension amount
- Net pension amount
- Pension deductions
- Pension-paying authority

For local acceptance, the most important requirement is a clear pension identity.

Examples of strong pension identity include:

- Slip pencen
- Penyata pencen
- Bayaran pencen
- Pesara
- Persaraan
- Pension payment
- Retirement payment
- Verified pension-paying authority


Pension slip accepted and ready

Accept locally when:

- Pension or retiree wording is strong
- The document purpose is pension payment
- No stronger active-employment payslip identity exists
- The document contains a pension payment structure or pension authority information


Pension slip accepted but incomplete

The document may remain classified as pension_slip even when:

- Statement period is missing
- Some payment amounts are unreadable
- Recipient information is incomplete

Missing fields should affect extraction completeness, not document identity.


Pension slip needs Gemini fallback

Use Gemini when:

- Pension and normal payslip signals conflict
- Pension wording is weak or incomplete
- The payment source cannot be determined
- OCR quality is poor
- The document resembles both a government payslip and pension statement


Pension slip needs manual review

Use manual review when:

- Python and Gemini cannot distinguish pension from active employment salary
- The document contains several different payment records
- The pension-paying authority cannot be confirmed
- The document is heavily cropped or incomplete


Pension checklist rule

Pension slips must not fill normal payslip checklist slots.

The pension result should remain available for later pension-specific business logic.


7. EPF Processing Policy

Document type

- epf


Critical information

For the current checklist workflow, the most important field is:

- Statement year

Useful supporting information includes:

- Member name
- IC number
- Member number
- Employer information
- Contribution records
- Account balances
- Dividends
- Withdrawals
- EPF account activity


EPF accepted and ready

Accept locally when:

- KWSP or EPF is clearly the document authority
- The document purpose is member savings, contributions, balances, or account activity
- Statement year is available
- No stronger payslip identity exists


EPF accepted but incomplete

The document may remain classified as EPF when:

- KWSP is clearly the issuer
- Contribution or savings information is present
- Statement year is unreadable

In this situation:

- The classification remains EPF
- The document cannot yet fill an EPF year checklist slot
- Gemini may be used to recover the missing year


EPF needs Gemini fallback

Use Gemini when:

- Statement year is missing
- KWSP appears, but it is unclear whether the file is an EPF statement or payslip
- The document contains an unfamiliar EPF layout
- OCR fails to read the statement heading or year
- The report contains conflicting employer payroll signals


EPF needs manual review

Use manual review when:

- Python and Gemini cannot identify the statement year
- The file contains several EPF statements from different years without clear separation
- The document appears incomplete
- The file combines EPF and another document type
- The issuer or statement purpose remains unclear


EPF checklist rule

An EPF statement can only fill an EPF year checklist slot when:

- Document type is EPF
- It does not need review
- Statement year is available and valid

The existing latest-year selection and duplicate-year handling remain controlled by Laravel.


8. RAMCI Processing Policy

Document type

- ramci


Important business boundary

The system type remains ramci.

This may include:

- Verified legacy RAMCI reports
- Specific current Experian credit-report products confirmed by the business

It must not automatically include every Experian-branded document.

Before final implementation, the business should confirm which actual Experian report family satisfies the RAMCI checklist requirement.


Critical information

For local acceptance, the system should identify:

- The actual issuing provider
- A valid personal credit-report purpose
- A structured credit-information report
- Applicant or subject identity where available

Useful credit-report sections may include:

- Credit facilities
- Outstanding balances
- Repayment history
- Payment conduct
- Account status
- Enquiry history
- Legal information
- Financial institutions


RAMCI accepted and ready

Accept locally when:

- Legacy RAMCI or an approved Experian report family is clearly the issuer
- The document is clearly a personal credit-information report
- The provider identity is established from strong document-level evidence
- No stronger CTOS issuer identity exists


RAMCI issuer evidence should consider

- Main report title
- Main logo or branding area
- Repeated page headers
- Footer
- Report name
- Consistent branding across the file
- Known report structure

One occurrence of RAMCI or Experian inside the report body is not enough.


RAMCI needs Gemini fallback

Use Gemini when:

- The file appears to be a credit report but the provider is unclear
- Experian branding exists, but the report family is not clearly approved
- RAMCI and CTOS signals conflict
- The provider name appears only once inside body text
- OCR does not capture the main heading or branding
- The report layout is unfamiliar


RAMCI needs manual review

Use manual review when:

- Python and Gemini cannot confirm the issuer
- The Experian product is not yet approved by the business
- The file combines several credit-report providers
- The file appears to be a loan statement rather than a credit report
- The report is incomplete or missing its identifying pages


RAMCI checklist rule

Only a verified RAMCI or approved Experian report family should fill the RAMCI checklist slot.

A generic credit report with an unknown provider should not fill the slot.


9. CTOS Processing Policy

Document type

- ctos


Critical information

For local acceptance, the system should identify:

- CTOS as the actual issuer
- A valid CTOS credit-information report purpose
- A recognised CTOS report family or structure
- Applicant or subject identity where available

CTOS reports may contain different sections depending on the product.

The system should not require every CTOS report to contain:

- CTOS Score
- CCRIS information
- Credit facilities
- One fixed report layout


CTOS accepted and ready

Accept locally when:

- CTOS is clearly the issuing provider
- The document is a valid CTOS report
- The report contains credit, legal, trade, business, or payment information
- No stronger RAMCI or Experian issuer identity exists


CTOS issuer evidence should consider

- Main report title
- Main logo or branding area
- Repeated page headers
- Footer
- Report product name
- Consistent CTOS identity across pages
- Known CTOS report structure

One occurrence of CTOS inside body text is not enough.


CTOS needs Gemini fallback

Use Gemini when:

- The document appears to be a credit report but the issuer is unclear
- CTOS and RAMCI or Experian signals conflict
- OCR cannot read the main branding area
- The report product is unfamiliar
- CTOS appears only once in body text or a reference section


CTOS needs manual review

Use manual review when:

- Python and Gemini cannot confirm the issuer
- The file combines reports from different providers
- The CTOS identifying page is missing
- The file is incomplete
- The document appears to be a generic loan or legal document rather than a CTOS report


CTOS checklist rule

Only a verified CTOS-issued report should fill the CTOS checklist slot.

A generic credit report should not be accepted as CTOS.


10. Other Document Processing Policy

Document type

- other

Use other only when the document is confidently readable but does not match a supported type.

Examples:

- Bank statement
- Employment letter
- Offer letter
- Loan statement
- Loan agreement
- Financing form
- Salary increment letter
- Employer confirmation letter

Do not use other as the general answer for every failed or uncertain case.


Other accepted locally

The system may accept other without Gemini when:

- The document is readable
- Its purpose is clear
- It is clearly unsupported
- There is no realistic possibility that it is one of the required documents


Other needs Gemini fallback

Use Gemini when:

- The local system returns other because confidence is low
- The document might be one of the supported types
- OCR quality is poor
- The issuer or purpose is uncertain
- The file may be a supported document with an unfamiliar layout


Other needs manual review

Use manual review when:

- Gemini also cannot identify the document
- The document is mixed
- The file is incomplete
- The document may have been uploaded under the wrong checklist category
- The document requires human interpretation


11. Multi-Page Processing Policy

Python should inspect pages individually before deciding the overall file type.


Same report across multiple pages

The file may be accepted as one document when:

- Pages share the same issuer
- Pages have a consistent report structure
- Later pages continue the content from earlier pages
- No unrelated document type appears

Example:

- Page 1 is a CTOS cover
- Page 2 contains CTOS credit information
- Page 3 contains CTOS legal information

This is one CTOS report.


Issuer appears only on the first page

The file may still be accepted when:

- The first page clearly establishes the issuer
- Later pages follow the same report structure
- Headers, footers, page numbering, or section continuity support the connection

Do not require every page to repeat the full provider name.


Mixed-document file

Treat the file as mixed when clearly different document families appear.

Examples:

- IC followed by payslip
- Payslip followed by EPF statement
- CTOS report followed by RAMCI report
- Several unrelated documents scanned together

A mixed file should not fill a checklist slot automatically.


Unrelated extra page

If most pages belong to one report but one page is unrelated:

- Do not ignore the unrelated page silently
- Mark the file as mixed or require review
- Do not accept the entire file as one clean document


Repeated same-type documents

Example:

- Three separate payslips combined into one PDF

This is not necessarily an invalid file, but it should not be treated as one single payslip period.

The system should either:

- Split the documents
- Identify each payslip separately
- Or send the file for review

Automatic splitting can be introduced separately.


12. User-Provided Type Conflict

The document type selected by the user is a hint.

It must not automatically override Python classification.

Example:

- User selects payslip
- Python strongly detects EPF

The system should:

1. Record the conflict
2. Avoid silent checklist assignment
3. Use Gemini fallback if the conflict may be resolved automatically
4. Use manual review if the conflict remains


13. Gemini Fallback Policy

Gemini should be called only when Python cannot provide a reliable and usable result.

Typical fallback reasons include:

- Classification is uncertain
- OCR quality is too poor
- IC side is unclear
- Payslip statement period is missing
- Payslip salary fields are insufficient
- Pension and payslip signals conflict
- EPF statement year is missing
- RAMCI or CTOS issuer is unclear
- An unfamiliar report layout is detected
- User-provided type conflicts with local detection
- Important fields contain conflicting values

Gemini should not be called when:

- Python result is already reliable and complete
- The document is clearly unsupported
- The file is clearly mixed and requires human handling
- The technical problem should instead be retried locally


14. Manual Review Policy

Manual review is the final safety layer.

Use manual review when:

- Python and Gemini both fail
- Gemini quota is unavailable
- The file contains multiple document types
- The file is incomplete or severely cropped
- The provider cannot be confirmed
- Important values conflict
- The document appears suspicious or altered
- The uploaded type conflict remains unresolved
- The document is unsupported but requires administrative attention

Manual review should preserve:

- Python’s detected type
- Gemini’s detected type if available
- Extracted text
- Missing fields
- Conflicting signals
- Reason for review

The reviewer should be able to understand why the system could not decide.


15. Technical Failure and Retry Policy

Some failures should be retried instead of immediately going to Gemini or manual review.

Retry local Python processing when:

- Python service connection temporarily fails
- Processing times out
- OCR engine temporarily crashes
- Internal service is unavailable
- A temporary file access problem occurs

Do not treat temporary service failures as document classification failures.


Use Gemini fallback when:

- Python processed the file successfully but could not understand it
- OCR result is available but insufficient
- The document structure is unfamiliar
- Important fields cannot be recovered locally


Use manual review when:

- Both processing paths fail
- The file itself is corrupted or incomplete
- The document is mixed
- The result remains conflicting


16. Checklist Assignment Principle

Checklist assignment remains controlled by Laravel.

Python and Gemini only provide document understanding.

The existing Laravel workflow decides:

- Whether the result needs review
- Whether a checklist slot can be filled
- Which payslip month maps to which slot
- Whether payslips form a consecutive sequence
- Which EPF years are selected
- Whether manual assignment overrides automatic assignment

The processing provider must not directly control checklist slots.


17. Processing Decision Order

Use this decision order:

1. Python processes the original document
2. Determine whether the document type is reliable
3. Determine whether critical fields are available
4. Check for conflicting signals
5. Check whether the document is workflow-ready
6. If ready, accept the Python result
7. If recoverable, use Gemini fallback
8. If unresolved, send to manual review
9. Let Laravel perform normalization and checklist assignment


18. Initial Critical Field Reference

IC front:

- IC side
- Full name or IC number
- Clear front-side evidence

IC back:

- IC side
- Clear reverse-side evidence

Payslip:

- Statement period
- Basic salary, gross income, or net pay
- No strong pension conflict

Pension slip:

- Strong pension identity
- Payment period where available
- Pension payment information where available

EPF:

- Statement year
- Clear KWSP or EPF identity
- Savings or contribution content

RAMCI:

- Verified RAMCI or approved Experian report family
- Clear personal credit-report purpose

CTOS:

- Verified CTOS issuer
- Clear CTOS credit-information report purpose


19. Business Confirmation Still Required

Before finalizing production rules, confirm:

1. Which Experian report products count as RAMCI
2. Whether every pension slip should remain outside the payslip checklist
3. Whether an IC front requires both full name and IC number or whether one is sufficient
4. Which salary field is the minimum acceptable payslip amount
5. Whether combined multi-document files should always require manual splitting
6. Whether clearly unsupported documents should be immediately rejected or shown to administrators
7. How long Gemini-unavailable documents should wait before manual review


20. Final Principle

The system should not ask only:

Did Python identify a document type?

It should ask:

1. Is the document identity reliable?
2. Are the important fields available?
3. Is the result suitable for the current workflow?
4. Can Gemini reasonably resolve the missing information?
5. Is human review required?

The goal is to accept strong local results, use Gemini only for recoverable uncertainty, and prevent incomplete or conflicting documents from silently entering the checklist workflow.