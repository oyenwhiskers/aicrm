# AI-Assisted Loan Lead Processing CRM — Prototype Concept

## 1. Objective

This system is a prototype CRM designed to streamline the process of handling loan leads from raw data intake to pre-screened applicant shortlist.

The goal is to:
- reduce manual data entry
- reduce manual chat follow-ups
- organize documents per lead
- extract structured information automatically
- assist in filtering eligible applicants

This is not just a storage system. It is a **process-driven lead handling workflow**.

---

## 2. Core Principle

Each lead is treated as a **case** that moves through a structured journey.

The system must always know:
- who the lead is
- what data is available
- what documents are collected
- what is missing
- what stage the lead is currently in

The system is responsible for maintaining clarity and progression.

---

## 3. High-Level Flow

1. Lead data is imported into the system
2. Lead becomes a structured record
3. User uploads documents per lead
4. System extracts data from documents using AI
5. System updates lead status based on completeness
6. User triggers processing
7. System evaluates and categorizes the lead

---

## 4. Module 1 — Leads Extraction Section

### Purpose
To convert raw input into structured leads inside the CRM.

### Input Types
- image / screenshot
- Excel file
- manual entry
- any raw lead source

### Expected Behavior
- system creates a new lead record for each entry
- each lead must at minimum have:
  - name
  - contact number
- duplicates should be avoided or flagged
- leads should be tagged with source (optional but recommended)

### Output
A clean list of leads ready for follow-up and document collection.

---

## 5. Module 2 — Lead Management & Interaction

### Purpose
To manage each lead and handle interaction.

### Capabilities

#### A. Document Upload
User can upload documents under each lead:
- IC
- 3 months payslip
- CTOS report
- RAMCI report

Each document must be linked to the correct lead.

#### B. Document Tracking
System should know:
- what documents are required
- what documents have been uploaded
- what is still missing

#### C. Quick WhatsApp Chat
- button opens WhatsApp using format:
  wa.me/+60XXXXXXXXX
- used for manual communication when needed

### Expected Behavior
- documents are organized per lead
- no mixing of documents across leads
- system maintains document completeness status

### Output
A structured case file per lead with associated documents.

---

## 6. Module 3 — AI Extraction & Data Structuring

### Purpose
To convert uploaded documents into structured data automatically.

### Trigger
Every time a document is uploaded.

### Process
- document is sent to AI (OpenAI ChatGPT)
- AI extracts relevant information depending on document type

### Examples
- IC → name, IC number, DOB
- payslip → income, employer
- CTOS/RAMCI → credit indicators

### Expected Behavior
- extracted data is stored under the lead
- system should not overwrite blindly; it should update intelligently
- extracted data should improve completeness of the lead profile

### Stage Update Logic
System updates lead stage based on document progress:
- no document → early stage
- partial documents → in progress
- all required documents → ready for processing

### Output
A structured data profile per lead derived from documents.

---

## 7. Module 4 — Processing & Eligibility Filtering

### Purpose
To evaluate leads and determine if they are suitable.

### Trigger
Manual "Process" button by user.

### Evaluation Basis
Based on extracted data:
- income level
- document completeness
- credit indicators
- any predefined business rules

### Expected Behavior
System categorizes leads into:
- eligible
- not eligible
- incomplete
- manual review required

### Important Principle
This is a **pre-screening step**, not final approval.

Human still has final control.

### Output
A filtered and categorized list of leads.

---

## 8. Lead Stage Concept

Each lead must have a stage at all times.

Example progression:
- new lead
- documents pending
- partially complete
- documents complete
- ready for processing
- eligible
- not eligible
- manual review

Stage must reflect actual progress, not guess.

---

## 9. System Behavior Rules

- every lead must be traceable
- every document must belong to a specific lead
- system must avoid data confusion
- system must maintain clarity over automation
- AI supports extraction and assistance, not uncontrolled decisions
- user should always understand current status of each lead

---

## 10. Success Criteria for Prototype

The prototype is successful if:

- user can import leads easily
- user can upload documents per lead without confusion
- system can extract useful data from documents
- system can track what is missing
- system can update lead stage automatically
- user can click process and get a categorized result
- user no longer needs to manually read every document

---

## 11. One-Line Summary

This system is a **lead-to-decision workflow CRM** that transforms raw leads into structured, document-backed, pre-screened applicants ready for human action.