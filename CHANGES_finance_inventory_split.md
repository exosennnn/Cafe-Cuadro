# Finance and Inventory as Separate Modules — Change Notes

Finance and Inventory each get their own role, dashboard, and sidebar.
No existing table was altered; the only database change is one new `roles` row.

---

## 1. Install

1. **Back up `unified_cafe_system` first.**
2. In phpMyAdmin, import `database/migration_finance_role.sql`
   (one `INSERT IGNORE` — additive, safe to re-run).
3. Copy the files in this package over your existing `unified/` folder,
   keeping the same paths.
4. Log in as each role and check the sidebar (see section 3).

No config change is needed. Run step 2 **before** posting a "Finance Staff"
job vacancy or creating a Finance Staff account (both point at `roles.role_id`).

---

## 2. What was wrong

| Problem | Where |
|---|---|
| Inventory Staff could open the Financial Report | `modules/reports/financial.php` (reports module was open to them) |
| Inventory Staff's dashboard showed "This Month's Net" | shared `modules/dashboard/index.php` |
| Finance had no role of its own — Owner only | `MODULE_ACCESS` |

---

## 3. Who can open what

| | Owner | Inventory Staff | Finance Staff |
|---|---|---|---|
| Inventory / Procurement | view (writes: Inventory Staff, PR approval: Owner) | manage | — |
| Stock & Purchase reports | yes | yes | — |
| Finance (dashboard, transactions, categories, Financial Report) | yes, can write | **no** | yes, can write |
| HR self-service + profile | — | yes | yes |

Landing pages (`dashboard.php` routes each role):

- Inventory Staff → `modules/inventory/dashboard.php` (stock + procurement only)
- Finance Staff → `modules/finance/dashboard.php` (income / expense / net, 6-month trend, top expenses, recent transactions)

---

## 4. Files

**New**
- `modules/finance/dashboard.php`
- `modules/finance/report.php` — the Financial Report, moved here
- `modules/inventory/dashboard.php`
- `includes/finance_nav.php` — tab bar shared by the finance pages
- `database/migration_finance_role.sql`

**Changed**
- `config/constants.php` — `ROLE_FINANCE_STAFF` (id 10), `MODULE_ACCESS`, new `finance.manage` capability
- `dashboard.php` — routes the two roles to their own dashboards
- `includes/sidebar.php`, `includes/header.php` — Finance Staff sidebar/theme; Owner's Finance group now lists Overview, Transactions, Categories, Financial Report
- `modules/finance/index.php`, `modules/finance/categories.php` — writes guarded with `requireCapability('finance.manage')`, tab bar added
- `modules/reports/index.php`, `modules/reports/purchases.php` — "Financial Report" tab removed
- `modules/reports/financial.php`, `modules/dashboard/index.php` — now plain redirects (old bookmarks keep working)
- `modules/hr_staff/jobs.php`, `includes/functions.php` — Finance Staff can be the role a job vacancy provisions on hire
- `pos/user_management.php` — Owner can create/list Finance Staff accounts
- `modules/employee/*.php` (attendance, leaves, requests, payslips, announcements, evaluations, profile), `modules/shared/request_view.php`, `modules/hr_staff/contract.php` — Finance Staff added to the allowed roles, same as Cashier / Inventory Staff

---

## 5. Things worth knowing

- **Owner keeps write access to Finance**, as before the split. To make the Owner
  view-only (like Inventory), delete `ROLE_OWNER` from `'finance.manage'` in
  `config/constants.php`.
- **Behaviour change:** Inventory Staff no longer see any finance number
  anywhere. The old "Inventory Overview" sidebar link and the extra "Main
  Dashboard" link were replaced by one "Inventory Dashboard" link.
- **Stale SQL dump.** In `database/eto bagong.sql.sql`, `activity_logs.log_id`
  and `finance_categories.fin_category_id` have no PRIMARY KEY / AUTO_INCREMENT.
  If a database was built from that dump, saving a finance transaction or category
  inserts the row and then fails with a 500 (the activity-log insert has no id).
  Existing code, not caused by this change. Re-export the dump from your live database.

---

## 6. Purchases now reflect in Finance (added afterwards)

Goal: whatever Inventory buys is deducted in Finance (as an Expense, net goes down).

**Fixed - a bug that was already in the code (`includes/functions.php`).**
`generateDocNumber()` opened its own transaction, but three actions call it from
inside a transaction of their own, so PDO threw *"There is already an active
transaction"* and every one of them failed:

| Action | File | Symptom |
|---|---|---|
| Receive a delivery (GRN) | `modules/procurement/receive.php` | "Failed to record delivery" - stock never went up and **no expense reached Finance** |
| Create a new Purchase Order | `modules/procurement/po_form.php` | "Failed to save purchase order" |
| Convert a Purchase Request to a PO | `modules/procurement/purchase_requests.php` | "Could not create the purchase order" |

`generateDocNumber()` is now nestable: it opens its own transaction only when the
caller has none (creating a PR, which was already fine, is unchanged). It still
uses `doc_sequences`, so existing PO/PR/GRN numbers carry on without a clash.
`receive.php` also now writes the real error to the PHP error log instead of
swallowing it.

**Where a purchase lands in Finance**

| Inventory action | Finance entry | Source shown in Finance |
|---|---|---|
| Receive a delivery against a PO (GRN) | Expense = received qty x PO unit price, category *Purchases* | Delivery |
| **New:** Stock In with an *Amount Paid* (direct purchase, no PO) | Expense = amount paid, category *Purchases* | Stock In |

- Stock In has a new **Amount Paid (PHP)** field, pre-filled with qty x the item's cost price
  (editable). Left blank = not a purchase (donation, transfer) and nothing is posted; the
  confirmation message says which happened.
- Stock and the Finance entry are saved in **one transaction**: both save, or neither.
- The expense goes to the system *Purchases* category, found by its system flag, so renaming
  it in Finance can no longer make a purchase silently skip Finance (if it is ever missing it is recreated).
- These entries are read-only in Finance (only Manual entries can be edited or deleted).
- Fixed the Finance "Source" column: every non-Manual row used to link to a *delivery* page,
  including POS sales and payroll. Each type now shows its own label.

**New migration:** `database/migration_stockin_finance.sql` - widens
`finance_transactions.reference_type` to include `'StockIn'`. Run it before using
Amount Paid (without it, a Stock In with an amount is refused and nothing is saved).

New/changed files for this section: `includes/functions.php`,
`modules/procurement/receive.php`, `modules/inventory/stock_movement.php`,
`modules/finance/index.php`, `modules/finance/dashboard.php`,
`database/migration_stockin_finance.sql`.

## 7. Inventory -> Finance -> POS Purchase Payment Gate

Run these migrations in order before using the updated procurement workflow:

1. `database/migration_inventory_finance_pos_workflow.sql`
2. `database/migration_inventory_payable_legacy_backfill.sql`

The first migration repairs missing `AUTO_INCREMENT` keys used by purchase,
delivery, supplier, Finance category, and activity-log inserts; deduplicates
identical Finance category rows without changing their IDs; adds branch-owned
purchase requests/orders and branch raw-item stock; and creates one payable per
delivery plus an explicit Inventory-item-to-POS-product mapping. Existing
aggregate raw stock is assigned to legacy/default branch 1. Review that
assignment before running the migration if existing stock physically belongs
to another branch.

Owner approves purchase requests and Pending purchase orders. Inventory Staff
can only create/receive orders for their assigned active employee branch.
Receiving a delivery stores only the quantities actually delivered, updates PO
receipt totals/status, and creates an UNPAID Finance payable. It does not update
Inventory or POS stock. Finance's **Mark Paid** action posts one expense and
releases the received quantity atomically; repeated payment attempts cannot
duplicate the expense or stock.

For a POS product sold directly from a purchase, link one active Inventory item
to one active POS product from the Inventory Items list and set the conversion
as **POS units per Inventory unit** (for example, one carton containing 12
sellable units uses 12). Direct mappings cannot overlap ingredient recipes.
Finance will not pay a directly mapped delivery if its converted received
quantity is fractional because POS stock is an integer. Unmapped items must be
used by at least one recipe; those receipts replenish branch raw stock and
continue through the existing recipe-based production flow.

The second migration marks legacy deliveries with an existing Finance Delivery
expense as already PAID, reusing that expense and deliberately not adding stock
again. Supplier phone fields now accept digits only, with a maximum of 11
digits, enforced in both the browser form and server validation.

## 8. Payroll preparation -> approval -> Finance payment

Run `database/migration_payroll_finance_workflow.sql` after the Inventory
workflow migrations. It adds branch and attendance-hour snapshots, adjustment
remarks, and a unique Finance transaction link to each payroll record. Existing
drafts awaiting Owner review become `SUBMITTED`; previously rejected drafts
remain available for HR correction; any existing Payroll Finance transaction
is linked without creating a second one.

HR Manager selects an active branch and date period. Basic pay is prorated from
monthly salary by period calendar days / 30. Clocked time is split into regular
hours (up to `WORK_HOURS_PER_DAY`) and overtime; overtime pay uses
`OVERTIME_RATE_MULTIPLIER` (default 1.25, configurable in
`config/constants.php`). Recorded late penalties and ABSENT attendance rows are
included in deductions. HR reviews each prepared payslip, records adjustment
remarks, and submits it. Owner can approve or return it. Only Finance can mark
approved (`PROCESSED`) payroll paid; that one transaction posts the Salaries
expense and updates the same payroll to `PAID`. Employee payslips show only
`PAID` records.
