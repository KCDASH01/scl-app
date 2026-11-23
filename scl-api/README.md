# SCL CRM (standalone)

Single-company CRM: customer app (`scl-app`), JSON API (`scl-api`), and operator admin (`scl-dashboard`).

There is no organizations table. Company profile is stored in `company_settings`. Users, leads, deals, and the rest of the CRM are single-tenant (no `organization_id`).

## Local setup

1. Point **both** Laravel apps at the **same** database (`DB_*` in `scl-api/.env` and `scl-dashboard/.env`).
2. From `scl-dashboard`:

```bash
composer install
php artisan key:generate
php artisan migrate
```

3. From `scl-api`:

```bash
composer install
php artisan key:generate
php artisan db:seed
```

Seed creates company **SCL**, superadmin **owner@crm.local** / **password**, and a default sales pipeline.

Or create your own superadmin interactively (only if no users exist yet):

```bash
php artisan crm:setup
```

4. From `scl-app`:

```bash
npm install
```

Create `.env.local`:

```
NEXT_PUBLIC_API_URL=http://localhost:8000/api
```

```bash
npm run dev
```

5. Serve the API (`php artisan serve --port=8000` in `scl-api`) and optionally the admin panel on another port.

## Roles

- **Superadmin:** full access, including team and departments.
- **Admin / manager:** team, departments, email templates, receipt settings, org-wide reports.
- **Employee:** assigned records only; cannot manage team.

## Unit-based course and hostel fees

Run migrations from `scl-dashboard` before using this feature. The migration
`2026_11_23_000001_add_unit_fee_schedules.php` adds schedules, allocation snapshots,
and an audit trail without changing existing fees or payments.

Duration remains optional. Courses without a duration use the original single
Price field. Courses with a duration use unit prices; a single submitted price
becomes period 1 in the selected unit without division or multiplication.

The follow-up migration `2026_11_23_000002_backfill_course_period_prices.php` copies
existing course prices into period 1 only for non-deleted courses with a valid
duration/unit and no period prices. It leaves the original price unchanged and
backs up each affected template in `course_period_price_backups`. It skips
existing schedules, is safe to retry, and never changes student records. Rollback
restores only templates that have not subsequently been edited; backups for
edited templates are retained. Deploy migrations from `scl-dashboard` with
`php artisan migrate` before deploying the updated API and frontend.

- Courses can define `period_prices` with unique period numbers within their
  duration. Gaps are allowed; collection follows ascending configured numbers.
  The course price is the sum of those rows.
- Conversion copies the configured prices and unit into the admission and course
  fee. Student price overrides must retain the course's configured period numbers.
  Later edits preserve already assigned student prices. Newly priced periods
  above the student's last configured period are appended automatically when
  saving the course; paid course fees reopen when these introduce a balance.
  Duration alone adds no charge. Historical gaps are not filled retroactively.
  Unit changes or duration reductions below assigned student periods are rejected.
- Admission allows per-period student prices and hostel stay/amounts. Employees
  may change unpaid periods; changing prices for periods with payments requires
  management access and a reason. A course change is allowed only before payments.
- Each fee type retains one parent deal. `fee_periods` holds the agreed prices;
  payments retain their original transaction identity and store allocations across
  periods. Allocation uses integer cents, in transaction ID order, under a parent
  row lock. Excess above the total outstanding balance is rejected for schedules.
- Fee status and stage are calculated from payments. The collection list displays
  the current unpaid period; Fee History includes all periods and transactions can
  be filtered by period. Hostel and course balances remain separate.
- Receipt snapshots preserve the issued allocation and balances. Correcting a
  transaction recalculates current allocations, revises that transaction's snapshot,
  and saves before/after history in `fee_audits`; other receipt snapshots remain intact.
- Existing legacy admissions must first be explicitly converted as described below.
  Course price edits are blocked while an attached admission remains unconverted.
- New hostel periods default to Not Stay with no charge. Confirm Stay and an amount
  in Admission to reopen hostel collection. The last paid applicable period remains
  visible until a subsequent hostel charge is configured.

## Production conversion: confirmed first-year legacy fees

The client confirmed that historical course and hostel amounts cover period 1.
This is a data conversion, not a second payment or a redistribution of course prices.
The schema migration `2026_11_23_000003_create_first_period_conversion_backups.php`
only creates the backup ledger; deploying/migrating alone does not convert data.

1. Back up the database and test this procedure on a restored production copy.
2. Put admission, course and payment writes into maintenance mode across all apps.
   Run `php artisan migrate --force` from `scl-dashboard`, then deploy the API.
3. From `scl-api`, run `php artisan fees:convert-first-period`. This previews the
   real conversion inside rolled-back transactions. Output includes course IDs,
   admission/deal IDs, fee kind, original amount, received, remaining and status.
   `--course=123` restricts preview or apply to one course.
4. Resolve reported errors, rerun preview, then run
   `php artisan fees:convert-first-period --apply` from `scl-api`.
5. Compare totals and transaction counts with the backup; deploy the frontend and
   reopen access. Rerunning the command skips converted admission snapshots.

Each course commits atomically with its admissions. If any linked fee fails
validation, that entire course is left unchanged; other valid courses may commit.
The command exits nonzero if any course failed. It blocks overpayments, Won records
without matching actual payments, received fields exceeding transaction totals,
missing/duplicate fee records, mixed schedules, and incomplete course durations.
It never synthesizes missing payments. Review these exceptions manually.

Courses without duration become 1 year, with their original template price in
Year 1. Existing valid units/durations are retained. Each legacy student's Year 1
uses that student's recorded deal value, including any agreed discount. Hostel
uses the existing hostel deal value, even if its admission checkbox was unchecked.
The initial conversion keeps fee totals, transaction identities, statuses and close
dates; it does not automatically charge later course periods already configured.
Save the course after reviewing those later prices to append them to students.

Original raw course/admission/deal/payment records are retained in
`first_period_conversion_backups`; fee changes also appear in `fee_audits`.
Existing receipt snapshots remain untouched. Missing snapshots are captured
against the original first-period agreement before any later fees are appended.
Do not roll back the schema to undo financial conversion: the backup-table migration
refuses to drop a populated ledger. Use a reviewed database restoration/reconciliation.
