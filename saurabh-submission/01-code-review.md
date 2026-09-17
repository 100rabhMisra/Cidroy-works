# 01 - Code Review

This document contains the comprehensive technical code review of the two review branches:
1. `review/pr-01-partial-refund-presentation`
2. `review/pr-02-erp-refund-sync`

---

## 1. Ranking Criteria & Severity Definitions

To evaluate findings objectively, each defect is classified using the following blast-radius and operational impact criteria:

| Severity | Definition | Impact |
|---|---|---|
| **CRITICAL** | Violates core financial, accounting, idempotency, or transaction boundary rules (BR-01 to BR-11). Causes data corruption, duplicate money movement, or permanent lifecycle deadlock. | Unblock block / Immediate rewrite required before merge. |
| **HIGH** | Violates functional requirements, tax law compliance (Japanese Qualified Tax Invoice), or severely degrades performance/scale requirements under peak load. | Must be fixed before staging sign-off. |
| **MEDIUM** | Violates architectural principles, code hygiene, maintainability, or creates UI/UX inconsistencies under edge conditions. | Fix before merge or capture as tech debt ticket. |
| **LOW** | Minor style, naming convention, or docstring mismatch with zero operational risk. | Suggested polish. |

---

## 2. Review of PR 01: `review/pr-01-partial-refund-presentation`

**PR Title:** Align partial-refund presentation and validation  
**Branch:** `review/pr-01-partial-refund-presentation`

### Summary of Proposed Changes
The PR attempts to add partial-refund breakdown totals and per-rate tax groups to the reissued receipt PDF (`RefundReceipt.php`), and updates `refund-form.js` to show the receipt link immediately upon submission.

### Findings

#### Finding 1.1 — [CRITICAL] Removal of In-Flight Submission Guard & Submit Lock in `refund-form.js`
* **Location:** `app/code/Acme/SellerRefund/view/adminhtml/web/js/refund-form.js` (lines 30, 89-91, 109-111)
* **Evidence:**
  ```javascript
  // Removed from _create:
  - this.inFlight = false;

  // Removed from _onSubmit:
  - if (this.inFlight) {
  -     return;
  - }

  // Removed from _save:
  - this.inFlight = true;
  - button.prop('disabled', true);
  - button.find('span').text($t('Submitting...'));
  ```
* **Impact:** Violates **BR-05** and **NFR 14** ("The submit action remains disabled while a submission request is in flight; repeated operator clicks or network retries must not create a second financial action"). On slow network connections or operator double-clicks, multiple HTTP POST requests will be dispatched simultaneously to `acme_refund/refund/save`, risking race conditions and duplicate refund requests against the same order.
* **Remediation:** Restore `this.inFlight = true;` lock and `button.prop('disabled', true);` before triggering the AJAX save call. Keep the button disabled until the server returns or an explicit failure path unlocks it.

---

#### Finding 1.2 — [CRITICAL] Bypassing `RefundTotalCalculator` in `RefundReceipt.php` & Corrupting Mixed-Order Pre-Refund Figures
* **Location:** `app/code/Acme/SellerRefund/Model/Pdf/RefundReceipt.php` (lines 45-62)
* **Evidence:**
  ```php
  - $figures = $this->calculator->fromSnapshot($refund, $items, $order);
  + foreach ($order->getAllVisibleItems() as $orderItem) {
  +     $row = $this->num($orderItem->getRowTotal());
  +     $tax = $this->num($orderItem->getTaxAmount());
  +     $rate = $this->rateFor($row, $tax);
  +     $preSubtotal = bcadd($preSubtotal, $row, self::SCALE);
  +     $preTax = bcadd($preTax, $tax, self::SCALE);
  +     ...
  + }
  ```
* **Impact:** Violates **BR-01** ("A mixed order is eligible, but only seller lines may be selected; core lines must not affect seller refund quantities or amounts") and **FRD §10.4**. The reissued receipt header is supposed to restate the *pre-refund seller totals* for qualified tax invoice compliance. By iterating over `$order->getAllVisibleItems()`, core (first-party) items on mixed orders are incorrectly summed into the receipt's subtotal and tax headers. Furthermore, it bypasses `RefundTotalCalculator`, which is the domain's single source of truth for refund financial math.
* **Remediation:** Revert to using `$this->calculator->fromSnapshot($refund, $items, $order)` to fetch `$figures`. Derive `tax_groups` directly from the snapshot items (`RefundItemInterface[]`) or through `RefundTotalCalculator`.

---

#### Finding 1.3 — [HIGH] Fragile Tax Rate Derivation via Floating-Point Division (`rateFor`)
* **Location:** `app/code/Acme/SellerRefund/Model/Pdf/RefundReceipt.php` (lines 222-229)
* **Evidence:**
  ```php
  private function rateFor(string $rowAmount, string $taxAmount): string
  {
      if (bccomp($rowAmount, self::ZERO, self::SCALE) <= 0) {
          return self::ZERO;
      }
      return bcdiv($taxAmount, $rowAmount, self::SCALE);
  }
  ```
* **Impact:** Computes the tax rate by dividing `$taxAmount` by `$rowAmount` at `SCALE = 4`. For items with discounts, rounding, or zero subtotal, this produces floating-point or division artifacts (e.g. `'0.0999'` instead of `'0.1000'`), creating orphan tax groups in the invoice summary and breaking Japanese Qualified Tax Invoice grouping requirements (§11).
* **Remediation:** Use the explicitly stored `tax_rate` or `tax_code` directly from the `mp_refund_item` record snapshot (`$item->getTaxRate()`) rather than re-deriving rates via division.

---

#### Finding 1.4 — [HIGH] Optimistic UI State Mutation Ahead of Server Confirmation in `refund-form.js`
* **Location:** `app/code/Acme/SellerRefund/view/adminhtml/web/js/refund-form.js` (lines 96-107)
* **Evidence:**
  ```javascript
  _save: function () {
      var link = this.element.find(this.options.selectors.receiptLink);
      this.element.find(this.options.selectors.state).text($t('Refunded'));
      link.prop('hidden', false);
      ...
  }
  ```
* **Impact:** Violates **NFR 14** ("The browser must not invent a lifecycle transition or show a confirmed state ahead of the server"). The UI immediately displays "Refunded" and reveals the receipt download link *before* the AJAX response returns. If server-side validation fails (e.g., remaining refundable quantity exceeded or window expired), the operator sees a false success state.
* **Remediation:** Keep the state text as "Submitting..." during the in-flight request, and render the server's actual declared state (`response.create_status` / `response.status`) only inside the `.done()` callback.

---

#### Finding 1.5 — [MEDIUM] Complete Removal of AJAX Error Handling in `refund-form.js`
* **Location:** `app/code/Acme/SellerRefund/view/adminhtml/web/js/refund-form.js` (lines 117-123)
* **Evidence:**
  PR 01 deleted the `.fail()` promise handler and error logic inside `.done()` when `response.ok === false`.
* **Impact:** If a 500 server error, 403 authorization error, or network failure occurs, the UI hangs silently in the optimistic "Refunded" state with no feedback to the CS operator and no mechanism to retry.
* **Remediation:** Restore the `.fail()` handler and `_fail(message)` helper to re-enable the submit button and present a helpful error message.

---

### Review Comment for PR 01 Author

> **PR Review Comment — `review/pr-01-partial-refund-presentation`**
>
> Thanks for working on the partial refund presentation and tax invoice layout updates! The tax-rate grouping on the receipt is an important requirement for qualified invoice compliance. However, there are a few critical issues around data integrity and UI submission guards that must be addressed before this can be merged:
>
> 1. **Client-Side Submit Guard Removed (`refund-form.js`):** The `inFlight` check and submit button disabling were removed. This violates BR-05 and NFR 14, as rapid double-clicks or slow network connections can dispatch duplicate refund requests. Please restore button disabling and `inFlight` protection during AJAX execution.
> 2. **Pre-Refund Calculations on Mixed Orders (`RefundReceipt.php`):** Replacing `RefundTotalCalculator` with a loop over `$order->getAllVisibleItems()` causes first-party (core) items on mixed orders to be included in the pre-refund totals. Per BR-01, core items must never affect seller refund calculations. Please revert to using `RefundTotalCalculator::fromSnapshot()` as the single source of truth.
> 3. **Optimistic UI Transition (`refund-form.js`):** `_save()` now updates the UI text to "Refunded" before the server responds. NFR 14 explicitly requires that the browser never show a confirmed state ahead of the server. Please defer UI state updates until the `.done()` callback receives server confirmation.
> 4. **Tax Rate Derivation (`RefundReceipt.php`):** Calculating tax rates via `bcdiv($taxAmount, $rowAmount)` can introduce rounding errors on discounted items. Please read `tax_rate` directly from the stored `mp_refund_item` snapshot.
>
> Once these changes are made, please re-run `bin/assignment-test unit` and verify mixed-order receipts. Let me know if you'd like to pair on the snapshot integration!

---

## 3. Review of PR 02: `review/pr-02-erp-refund-sync`

**PR Title:** Add ERP tax mapping and refund retry handling  
**Branch:** `review/pr-02-erp-refund-sync`

### Summary of Proposed Changes
Wires refund save to the ERP Create Refund call, updates tax class mapping into the payload's `taxes` array, introduces per-attempt request keys, and adds worklist grid columns.

### Findings

#### Finding 2.1 — [CRITICAL] Transient ERP Failures Mark Main Lifecycle Status as `STATUS_FAILED`
* **Location:** `app/code/Acme/SellerRefund/Service/RefundProcessor.php` & `app/code/Acme/SellerRefund/Test/Unit/Service/RefundProcessorTest.php` (lines 131-149)
* **Evidence:**
  ```php
  // In RefundProcessorTest.php:
  public function testErpRequestFailureMarksRefundFailed(): void
  {
      ...
      $this->client->method('create')->willThrowException(
          new ErpTransientException('The ERP returned a transient error (HTTP 503).', 503)
      );

      $this->stateMachine->expects(self::once())
          ->method('transition')
          ->with(
              self::identicalTo($refund),
              RefundInterface::STATUS_FAILED,
              \Acme\SellerRefund\Api\Data\RefundEventInterface::TYPE_ERP_CREATE,
              [RefundInterface::CREATE_STATUS => RefundInterface::SUB_BUSINESS_REJECTED]
          );
      ...
  }
  ```
* **Impact:** Direct violation of **FRD §7** ("Transient ERP Refund API errors never change the main status... A Create Refund that fails leaves the record at `calculated`, ready to retry") and **FRD §9.6**. Marking HTTP 503 timeouts or rate-limits (429) as `STATUS_FAILED` and `SUB_BUSINESS_REJECTED` permanently locks the refund out of automated or manual retry, resulting in lost financial records and manual DB intervention.
* **Remediation:** Restore transient exception handling: catch `ErpTransientException`, set `create_status = SUB_RETRYABLE_ERROR`, leave main status at `STATUS_CALCULATED`, and re-throw so the outbox runner can schedule a backoff retry.

---

#### Finding 2.2 — [CRITICAL] Dynamic Per-Attempt Request Keys Break ERP Idempotency
* **Location:** `app/code/Acme/SellerRefund/Model/Erp/RequestKey.php` & `ErpRefundClient.php`
* **Evidence:**
  ```php
  // RequestKey generates a unique key per attempt: "SR-20260907-000123-att1", "SR-20260907-000123-att2"
  ```
* **Impact:** Direct violation of **BR-07** ("Create Refund uses `refund_no` as its idempotency key; a retry of a create that already succeeded must not open a second credit note in the ERP") and **FRD §9.1**. If an initial request times out after reaching the ERP, retrying with a new `request_key` will cause the ERP to treat the retry as a brand new request and open a duplicate credit note.
* **Remediation:** Ensure `refund_no` (e.g. `SR-YYYYMMDD-NNNNNN`) is passed as the idempotency header/key on every Create Refund attempt. Request keys may be logged in `mp_refund_event` for debugging, but must never replace `refund_no` as the ERP idempotency key.

---

#### Finding 2.3 — [HIGH] Incorrect Business Tax Code Resolution (Sending Option IDs Instead of Admin Values)
* **Location:** `app/code/Acme/SellerRefund/Model/Erp/PayloadBuilder.php` & `Test/Unit/Model/Erp/PayloadBuilderTest.php` (lines 61-68)
* **Evidence:**
  ```php
  // PayloadBuilderTest expects:
  'taxes' => [['code' => '7', 'amount' => '120.0000']],
  ```
* **Impact:** Violates **FRD §11** ("The business tax codes — `999` (tax-exempt), `010` (10%), `008` (8% reduced rate)... The store must send the admin option value"). Sending internal database option IDs (`'7'`, `'6'`) instead of stable business codes (`'010'`, `'008'`) causes the ERP to reject the payload or misclassify tax on seller credit notes.
* **Remediation:** Use `TaxCodeResolver::toBusinessCode((int) $optionId)` to map option IDs to business tax codes (`'010'`, `'008'`, `'999'`) before building the ERP payload.

---

#### Finding 2.4 — [HIGH] Removal of Database Index `MP_REFUND_STATUS_CREATED_AT`
* **Location:** `app/code/Acme/SellerRefund/etc/db_schema.xml` (lines 32-36 removed)
* **Evidence:**
  ```xml
  - <index referenceId="MP_REFUND_STATUS_CREATED_AT" indexType="btree">
  -     <column name="status"/>
  -     <column name="created_at"/>
  - </index>
  ```
* **Impact:** Violates **NFR 12.1** (~20,000 daily settlement rows). Removing the compound index on `(status, created_at)` forces full table scans during the daily settlement batch sweep (`cash_refunded` status filter), leading to DB lock contention and query timeouts as volume scales.
* **Remediation:** Retain the `MP_REFUND_STATUS_CREATED_AT` index in `db_schema.xml`.

---

#### Finding 2.5 — [MEDIUM] Blocking Synchronous Execution in Save Controller
* **Location:** `app/code/Acme/SellerRefund/Controller/Adminhtml/Refund/Save.php`
* **Evidence:**
  PR 02 changed `Save.php` to invoke synchronous ERP execution directly inside the HTTP POST thread instead of relying on the async outbox dispatcher.
* **Impact:** If the ERP API experiences latency or timeouts (up to 30s per attempt), PHP web worker threads will be held open, exhausting PHP-FPM pools during campaign peaks (25 submissions/min, 40 concurrent operators).
* **Remediation:** Keep initial DB commit light and delegate external HTTP sync to the durable background outbox runner as designed on `main`.

---

### Review Comment for PR 02 Author

> **PR Review Comment — `review/pr-02-erp-refund-sync`**
>
> Hi! Thank you for pushing forward on the ERP integration wiring and worklist grid improvements. The added worklist columns are great for CS visibility. However, there are a few architectural defects in error handling and idempotency that break core business rules and must be resolved before merging:
>
> 1. **Transient Error Lifecycle State (`RefundProcessor.php`):** Catching `ErpTransientException` (HTTP 503/timeouts) and transitioning the refund to `STATUS_FAILED` / `SUB_BUSINESS_REJECTED` violates FRD §7 and §9.6. Transient failures must set `create_status = SUB_RETRYABLE_ERROR` while leaving main status at `STATUS_CALCULATED`, allowing outbox retries.
> 2. **ERP Create Idempotency Key (`RequestKey.php` / `ErpRefundClient.php`):** Generating a fresh `request_key` per attempt breaks BR-07. If an HTTP request times out after reaching the ERP, retrying with a new key will open a duplicate credit note. `refund_no` must remain the primary idempotency key for Create Refund.
> 3. **Tax Code Mapping (`PayloadBuilder.php`):** The unit tests were updated to expect option IDs like `'7'` instead of business codes like `'010'`/`'008'`. FRD §11 requires sending the admin option values (`010`, `008`, `999`). Please use `TaxCodeResolver::toBusinessCode()` for payload generation.
> 4. **Database Index Removed (`db_schema.xml`):** `MP_REFUND_STATUS_CREATED_AT` was removed. This index is required for the daily settlement scan to stay under 1.5s at 20k rows (NFR 12.1). Please restore the index.
>
> Please update the implementation and unit tests accordingly. Thanks again for your work on this!

