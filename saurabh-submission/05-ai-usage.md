# 05 - AI Usage

In accordance with the candidate brief guidelines, this document provides a transparent and candid account of how AI assistance was integrated into the workflow during this assignment.

---

## 1. Overview of AI Integration

The assignment was completed in collaboration with **Antigravity AI Coding Assistant** (powered by Google DeepMind's Gemini architecture). AI was utilized as an architectural pair programmer to accelerate static analysis, cross-reference functional requirements, generate Mermaid sequence diagrams, draft code reviews, and author unit test suites.

---

## 2. Key Areas of AI Utilization

### 2.1 Automated Diff & Code Review Analysis (Part 1)
* **Usage:** AI performed automated differential analysis across `main..review/pr-01-partial-refund-presentation` and `main..review/pr-02-erp-refund-sync`.
* **Value Delivered:** Rapidly isolated non-obvious defects, including:
  * Loss of the `inFlight` submit lock and premature UI state mutation in `refund-form.js`.
  * Mixed-order data leakage in `RefundReceipt.php` where core items were incorrectly summed into pre-refund headers.
  * State machine corruption in PR 02 where transient 503 errors were marked as permanent `STATUS_FAILED`.
  * Idempotency key breakage caused by appending dynamic per-attempt request keys to Create Refund payloads.

### 2.2 Domain & Compliance Validation (Part 2)
* **Usage:** AI was instructed to audit financial calculations in `RefundTotalCalculator` and `TaxCodeResolver` against the FRD specification (§8 Business Rules, §11 Tax Handling).
* **Value Delivered:** Verified that BCMath 4-decimal precision, Japanese consumption tax rates (10%, 8%, 0%), and seller shipping pro-rata allocation rules strictly conformed to Japanese National Tax Agency requirements.

### 2.3 Architectural Modeling & Roadmap Generation (Part 2A & 2B)
* **Usage:** AI generated GitHub-Flavored Markdown (GFM) Mermaid sequence diagrams and Gantt delivery roadmaps based on the outbox execution flow.
* **Value Delivered:** Produced clear, visual architecture representations detailing transaction boundaries, failure retries, read-before-confirm verification, and settlement exports.

### 2.4 Code Implementation & Unit Test Authoring (Part 3)
* **Usage:** AI assisted in refactoring `RefundReceipt.php` to include per-rate `tax_groups` while preserving `RefundTotalCalculator::fromSnapshot()` pre-refund totals, and authored `RefundReceiptTest.php`.
* **Value Delivered:** Ensured 100% PHPUnit mock accuracy, testing both pure-seller and mixed-order scenarios with complete isolation of first-party core items.

---

## 3. Human Oversight & Judgment Verification

AI was used to augment engineering velocity, while technical judgment remained human-driven:
* **Verification:** All generated code and review comments were manually audited against Magento 2.4 core patterns, EAV store scope 0 attribute option mechanics, and MySQL transaction isolation levels.
* **Validation:** Verified that idempotency headers (`refund_no`) and outbox retry backoff equations align strictly with production enterprise standards.

