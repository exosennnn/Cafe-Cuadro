# Employment Contract No. + Request Forms — Change Notes

Two additions to the existing Café Cuadro HRMS. Both build on what was already
there: no existing table was altered, no existing feature was replaced.

---

## 1. Install

1. **Back up `unified_cafe_system` first.**
2. In phpMyAdmin, select the `unified_cafe_system` database and import
   `database/migration_contracts_requests.sql`.
   It is additive only — `CREATE TABLE IF NOT EXISTS` and `INSERT IGNORE` —
   so it touches no existing row and is safe to re-run.
3. Copy the changed and new PHP files over your existing `unified/` folder.
4. Log in and check the new sidebar entries appear for each role.

No config change is needed.

> **Note on the SQL dump.** `database/eto bagong.sql.sql` is out of date
> relative to the running code — it is missing `job_offers.offer_details` and
> `job_offers.response`, which live pages already query. Your actual database
> has them. The migration does not depend on that dump; it runs against your
> live schema. Worth re-exporting the dump at some point so it matches reality.

---

## 2. Employment Contract No.

### What it is

The contract itself is unchanged — still assembled from `job_applications` +
`job_offers` by `buildEmploymentContractPdf()`. What is new is the record
around it: a unique number and two e-signatures, in `employment_contracts`.

**Format:** `EC-2026-0001` — prefix, year, then a per-year counter.

### When it is generated

At the point hiring is finalised: the **Create Employee Record** action in
*HR Staff → Review Applications*, which only runs once the applicant has
accepted the offer. The number is issued **inside the existing transaction**
that creates the employee record, so an employee can never exist without a
contract number, or vice versa. The flash message now reports both.

Issuing is idempotent — re-running the action returns the number already
issued rather than burning a second one.

**Employees hired before this change** get a number automatically the first
time their contract is opened. There is also a commented-out bulk backfill at
the end of the migration file if you would rather number everyone at once.

### Where it is displayed

| Location | What shows |
|---|---|
| Employment Contract page | In the toolbar, in the document's reference bar, and in the closing footnote |
| Contract PDF | Under the letterhead and in the closing line |
| **Employee Profile** (`modules/employee/profile.php`) | Under Employment Details, with a *View contract* link and an "Awaiting signature" badge if unsigned |
| HR Staff → Employee Records | New **Contract No.** column (one batched query, not one per row) |

### The contract page

`modules/hr_staff/contract.php` now renders a formal document on screen:
letterhead, reference bar, the parties, all 16 numbered clauses, and the
signature block. It has a print stylesheet that strips the app chrome.

**Behaviour change worth knowing:** this URL used to download a PDF
immediately. It now opens the document. The PDF is unchanged and still one
click away:

```
contract.php?application_id=N              → document on screen  (new default)
contract.php?application_id=N&format=pdf   → the original PDF download
```

The two links that pointed here were relabelled from "Download" to "View".
The offer email still attaches the PDF exactly as before — that path calls
`buildEmploymentContractPdf()` directly and was not touched.

### E-signatures

At the bottom of the contract, each party's block shows **name, role, and date
signed**, plus a short verification ref so a printed copy can be checked
against the database.

- **Employee** signs their own contract. Their recorded role is the position
  being contracted, not their system role.
- **Employer** is signed by an **HR Manager** or the **Owner**, recorded as
  "Authorized Company Representative".

Signing requires an explicit confirmation tick, is written once, and cannot be
overwritten or undone through the UI. Every signature is written to the audit
log. An unsigned party still prints a blank line, so a half-signed contract can
be completed by hand.

### Note on clause wording

The 16 clauses moved out of `buildEmploymentContractPdf()` into
`employmentContractClauses()` in `includes/contracts.php`. The wording is
byte-for-byte what it was; it now lives in one place so the PDF and the
on-screen document render from the same source and cannot drift apart. Edit
the clauses there and both update.

---

## 3. Request Forms

### Types

Leave, Overtime, General, and Document/Certificate — one `request_forms` table,
with type-specific columns filled in only for the type that uses them.

Numbers are per-type: `LV-2026-0001`, `OT-2026-0001`, `GR-2026-0001`,
`DC-2026-0001`.

### Approval route

The existing flow, unchanged:

```
Step 1  Employee           files                    → PENDING_MANAGER
Step 2  Employee Manager   reviews, forwards        → PENDING_HR
Step 3  HR Staff           processes, forwards      → PENDING_APPROVAL
Step 4  HR Manager         final approval           → APPROVED
```

A rejection at any step ends the request; the employee may withdraw a request
while it is still pending. The status values are deliberately the same strings
`leave_requests` uses, so the existing `leaveStatusLabel()` /
`leaveStatusColor()` helpers apply as-is.

### E-signature records

Every action — including the original submission — appends a row to
`request_approvals` with **name, role, date/time, action, and remarks**, plus a
verification ref. The table is append-only; nothing is ever updated or deleted,
so the document view shows the history exactly as it happened.

Actor name and role are stored as literal text, not just a `user_id`, so a
signed record still reads correctly if that person later changes role or leaves.

Rejections require a reason — the filer is entitled to one.

### Pages

| Role | Page | Does |
|---|---|---|
| Employee / Cashier / Inventory Staff | `modules/employee/requests.php` | File and track own requests, withdraw pending ones |
| Employee Manager | `modules/employee_manager/requests.php` | Step 2 queue |
| HR Staff | `modules/hr_staff/requests.php` | Step 3 queue |
| HR Manager | `modules/hr_manager/requests.php` | Step 4 final approval |
| All of the above + Owner | `modules/shared/request_view.php` | Formal document view |

The three reviewer pages are five lines each — the queue, decision handler, and
modals live once in `includes/request_review_page.php`, so a change to the
review screen applies to all three steps at once.

### Document view

`request_view.php` renders the request as a formal document: letterhead,
reference bar, **I. Employee Details**, **II. Request Details**, and
**III. Approval and E-Signature History** — a timeline of every step, with
steps not yet reached shown as outstanding rather than silently absent.
Prints cleanly.

An approver whose step is the one currently waiting can act directly from the
document, without going back to their queue.

### Access control

Employees see only their own requests. The three approval roles and the Owner
can open any request. Every decision re-checks the request status **under a row
lock** and guards the `UPDATE` by the expected status, so two approvers
clicking at the same moment cannot both move the same request — the second is
told it already moved.

### Approved leave

An approved leave request marks `attendance` as `ON_LEAVE` for each date,
exactly as the leave flow always did, and leaves days the employee already
clocked in for untouched. See section 4 for how leave was merged in.

---

## 4. Leave is now part of Request Forms

Leave is no longer a separate module. It is one of the four Request Forms
types, so there is **one place to file a request and one place to approve one**.

### How it was merged without breaking anything

Thirteen files read `leave_requests` — the Employee, Employee Manager, HR Staff,
HR Manager and Owner dashboards, Owner reports and analytics, and the
`cashierOnApprovedLeaveToday()` attendance check. Moving leave out of that table
would have broken every one of them.

So `leave_requests` **stays the canonical leave record**. Request Forms writes
to both tables in the same transaction:

| Action in Request Forms | What happens to `leave_requests` |
|---|---|
| Employee files leave | Companion row inserted, linked by `request_forms.leave_id` |
| Employee Manager forwards/rejects | `status`, `manager_reviewed_by/_at`, `manager_remarks` |
| HR Staff forwards/rejects | `status`, `hr_processed_by/_at`, `hr_remarks` |
| HR Manager approves/rejects | `status`, `reviewed_by/_at`, `review_remarks` |
| Employee withdraws | `status = CANCELLED` |

The columns written are exactly the ones each step has always owned, so leave
approved through Request Forms is indistinguishable from leave approved the old
way to every page that reads them. **No dashboard, report, analytics page,
balance calculation or attendance check needed a single line changed.**

### Existing leave was brought across

Section 8 of the migration imports every row already in `leave_requests` into
Request Forms and **rebuilds its approval history** from the reviewer columns it
already carried — who reviewed it, when, and with what remarks, at each step.
Staff open Request Forms and see their full history, not an empty list.

Imported entries get an e-signature ref derived from the same facts, so they
render consistently alongside newly signed ones. The import is guarded by
`NOT EXISTS`, so re-running the migration is safe.

### The old pages

`modules/{employee,employee_manager,hr_staff,hr_manager}/leaves.php` are now
redirects to the matching Request Forms page, so old bookmarks and any link
still pointing there land correctly rather than 404. The originals are kept in
`_retired/` and can be deleted once you're satisfied.

The three dashboard shortcuts that pointed at `leaves.php` now point at
`requests.php`, and the duplicate "Leave" sidebar entries are gone — one
Request Forms entry per role instead of two that behaved alike.

### Nothing was lost

The **leave balance check** HR Staff had on the old page is carried over, and
is now on every reviewer's screen rather than only HR Staff's. Each leave
request shows days used against entitlement for that leave type this year, and
the decision dialog spells out what the balance would be if approved, flagging
it in red when a request would exceed entitlement.

Double-booking is still blocked. The check now runs against `leave_requests`
alone, which — since every Request Forms leave has a row there — covers both
leave filed through the new module and leave filed before the merge.

## 5. File manifest

**New**

```
database/migration_contracts_requests.sql
includes/doc_numbers.php              sequence generator + signature refs
includes/contracts.php                contract numbers, e-signatures, clause text
includes/requests.php                 request forms library
includes/doc_styles.php               shared formal-document styling
includes/request_review_page.php      shared reviewer screen
modules/employee/requests.php
modules/employee_manager/requests.php
modules/hr_staff/requests.php
modules/hr_manager/requests.php
modules/shared/request_view.php
```

**Modified**

```
includes/functions.php                contract builder: shared data + clauses + e-signatures
includes/sidebar.php                  Request Forms nav for 6 roles
modules/hr_staff/contract.php         now a document view; PDF at &format=pdf
modules/hr_staff/applications.php     issues Contract No. in the hire transaction
modules/hr_staff/employees.php        Contract No. column
modules/employee/profile.php          Contract No. in Employment Details
modules/applicant/my_applications.php link relabelled

modules/employee/leaves.php           retired -> redirect to Request Forms
modules/employee_manager/leaves.php   retired -> redirect
modules/hr_staff/leaves.php           retired -> redirect
modules/hr_manager/leaves.php         retired -> redirect
modules/employee/dashboard.php        leave shortcut -> Request Forms
modules/employee_manager/dashboard.php  same
modules/hr_staff/dashboard.php          same
```

**Kept for reference**

```
_retired/                             the four original leave pages
```

---

## 6. Worth checking after install

No live PHP/MySQL environment was available while building this, so it was
written and reviewed against the code rather than executed. Please walk these
once:

- [ ] Hire an applicant end-to-end → Contract No. issued, appears on Employee Profile
- [ ] Open the contract → document renders; **Download PDF** still produces the PDF
- [ ] Sign as employee, then as HR Manager → both signatures show name, role, date
- [ ] Send a job offer → contract PDF still attaches to the email
- [ ] File one of each request type → all four numbers issue correctly
- [ ] Walk one request through all four steps → history shows four signed entries
- [ ] Reject at step 2 and again at step 4 → both end the request, reason recorded
- [ ] After import, confirm old leave requests appear in Request Forms **with their approval history**
- [ ] File leave via Request Forms, walk it to approval, then confirm the Employee, HR and Owner dashboards still show the right leave counts
- [ ] Confirm leave balances on the reviewer screen match what the old HR Staff page showed
- [ ] Visit an old `leaves.php` URL and confirm it redirects rather than 404s

### Two things I could not verify without a database

- **The `doc_sequences` problem.** That table has no primary key and already
  contains duplicate `(doc_type, year)` rows, which makes the existing
  `generateDocNumber()` unreliable for Procurement's PO and GRN numbers. I did
  **not** touch it — fixing it is a separate job with its own risk, and it was
  outside what you asked for. Contract and request numbers use a new
  `document_sequences` table with a proper composite primary key, so they are
  not exposed to that bug. Flagging it because it will eventually bite
  Procurement.
- **PHP syntax** was checked with a structural parser (braces, quotes, tags),
  not `php -l`, since no interpreter was available in this environment. Run
  `php -l` over the changed files before deploying if you can.
