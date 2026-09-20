# 06 - Questions and Next Steps

This document captures open technical questions, core assumptions made during design, explicit scope boundaries, and prioritized next steps for production deployment and the discussion round.

---

## 1. Key Technical & Domain Assumptions

During the review, design, and implementation phases, the following key assumptions were established based on the functional specification and provided baseline code:

1. **Financial Settlement Scope:** The system records offline bank transfers conducted by Finance; money movement happens externally. The store's responsibility terminates upon registering the transaction reference and confirming settlement to the ERP.
2. **Single Active Refund per Order (v1 Baseline):** In accordance with **BR-05**, the v1 design assumes one active refund per order. Multi-refund history per order is handled by tracking `qty_refunded_before` per line, but concurrent simultaneous submissions against the same order are guarded by `OrderLock`.
3. **EAV Tax Class Mapping Contract:** Local EAV `mp_tax_class` option values at `store_id = 0` represent the authoritative business tax codes (`010` for 10%, `008` for 8% reduced rate, `999` for tax-exempt). Local database option IDs are treated as environment-specific internal details.
4. **ERP Stub Contract Alignment:** The Node.js ERP stub contract (`/erp-api/v1/refunds`) reflects production response semantics, including 503 transient errors, 429 rate limits, and 422 business rejections.

---

## 2. Explicit Scope Boundaries & Conscious Trade-offs

To optimize focus within the 8-hour working budget, explicit scope boundaries were maintained:

* **In Scope:** Complete review of proposed PRs, end-to-end refund sequence design, architecture risk analysis, implementation of snapshot-driven tax invoice PDF grouping, and automated unit test suite.
* **Out of Scope (By Design):**
  * *UI Polling Refactor:* The 2-second client-side status polling in `refund-form.js` was documented as a High risk in the Architecture Review (Part 2B) with an SSE/WebSocket target-state proposal, but left untouched in JS to avoid unrequested frontend overhaul.
  * *AMQP Message Broker:* Maintained DB Outbox pattern per runtime environment constraints (no RabbitMQ/Redis container provided).

---

## 3. Open Questions for Domain Stakeholders

The following sharp questions should be clarified with Finance, ERP, and CS Operations leads:

### Questions for Finance & ERP Leads:
1. **Multi-Partial Refund Support:** Does the business require supporting multiple sequential partial refunds against a single seller order over time (e.g. refunding 1 item today and another next week), or does a single refund close the order's refund window?
2. **ERP Credit Note TTL:** What is the TTL (Time-To-Live) for a `refund-pending` credit note in ERP? If Finance takes more than 14 days to complete the bank transfer, does the ERP credit note auto-expire or require explicit cancellation?
3. **Tax Invoice Exemption References:** For tax-exempt seller items (`tax_code = 999`), is the store required to pass an exemption reason code or tax certificate number in the Create Refund payload to comply with National Tax Agency audits?

### Questions for Customer Support Operations:
4. **Manual Delivery Date Overrides:** When an external delivery carrier feed fails to populate `mp_delivered_at`, what is the escalation workflow for CS leads to verify delivery and unblock the 14-day refund window?

---

## 4. Prioritized Next Steps

### Phase 1: Immediate Pre-Deployment Tasks
1. **Run Full Integration Test Suite:** Execute `bin/assignment-test all` inside Docker containers to verify end-to-end stack health and outbox worker execution.
2. **Setup Prometheus/Grafana Telemetry:** Add custom Magento metrics for:
   * Outbox queue backlog count (`acme_refund_outbox_pending_count`).
   * ERP Refund API response latencies and HTTP error rates (`acme_refund_erp_request_duration_seconds`).
   * Settlement sweep execution time.

### Phase 2: Discussion Round Preparation (75–90 Mins)
* Come prepared to walk through the end-to-end sequence diagram, defend the PR 01/PR 02 code review findings, explain the `RefundReceipt` tax grouping fix, and demonstrate live system behavior.

