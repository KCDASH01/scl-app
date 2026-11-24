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

## Deleted lead visibility

Normal HTTP pages and API endpoints show lead-related records only while their
associated lead exists and is not soft-deleted. A request-level middleware enables
the shared model visibility scope before route binding. Admissions, both fee
collections, payments/receipts, attendance, documents, activities, notes, proposals,
notifications, reports, exports and derived totals follow the same rule. Direct
detail/edit/payment URLs for hidden records return 404. Standalone tasks or deals
without a lead remain available because they do not belong to a deleted lead.

Deleting a lead still performs only the existing lead soft-delete and audit log.
Related rows/payments/uploads are retained. No retrospective data migration is
needed: existing orphan/deleted-lead records are hidden on the next request.
Visibility ends with the request (including streamed exports); console conversion,
backups and financial reconciliation retain historical access. Enrollment number
generation explicitly includes hidden history so deleted student IDs cannot be
reused. Lead deletion refreshes all cached page queries in the frontend.

Deploy the updated API and dashboard code and rebuild the frontend using its
existing production API URL. Clear Laravel caches in both apps. No migration or
first-period conversion command is required for this visibility change.

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

Course templates are validated and committed separately. Each individual admission
and all its active deals, payments, allocation snapshots, receipts, backups and
audits convert in one transaction. Expected financial validation failures roll back
only that admission and continue to the next student, including within the same
course. A valid template may therefore become 1 year even when some or all of its
admissions are skipped. Unexpected database/infrastructure failures abort the run;
previously committed admissions remain committed. Never rerun blindly after an
infrastructure failure: inspect the error, then review preview again.

Only non-deleted courses and admissions are examined. Admissions associated with
deleted/missing leads are excluded and reported. Only active course/hostel deals
and their own payments are converted. Deleted deals are read solely for ambiguity
detection: ANY historical payment on a deleted course or hostel deal causes that
admission to be skipped, even if an active replacement exists. Such payments are
never transferred, allocated or changed. No deleted record is restored, removed
or given a new deletion timestamp. An unpaid deleted deal is left untouched and
does not by itself block a valid active deal.

Before any writes, a read-only MySQL/MariaDB preflight requires all participating
tables to exist and use InnoDB. Nontransactional/missing tables abort both modes;
the command never changes storage engines or repairs schema automatically.

Other skipped cases include missing/duplicate active fee deals, required hostel
fees missing, duplicate active admission relationships, mixed schedules, existing
allocations without a matching admission schedule, branch/course relationship
mismatches, invalid amounts, overpayments, Won fees without matching payments,
received fields exceeding payment totals, or conflicting legacy hostel amounts.
Incomplete/invalid course duration/pricing blocks that course, with affected
admission IDs reported. The converter never fabricates missing fees/payments or
repairs ambiguous relationships.

Every run prints detailed excluded/skipped admission IDs/reasons and a final
`SUMMARY` JSON line with:

- courses examined, converted templates, already configured templates, skipped;
- admissions examined, newly eligible/converted, excluded leads, financial skips,
  already converted, and admissions blocked by invalid course templates;
- course/hostel deals converted and payment allocations generated;
- payment counts and totals before/after across ALL historical payments, including
  payments attached to deleted deals, and an unchanged-invariants flag.

In preview, converted counts are hypothetical. Every database write, including
course/admission changes, backups and audits, rolls back. Preview executes the same
service and validation as apply; admission savepoints allow valid hypothetical
conversions to continue after a record-level failure. Pause all writes for reliable
before/after reconciliation; these aggregate totals do not replace a full backup.
`eligible_admissions` counts newly convertible legacy admissions; validated
already-converted records have a separate count. Already-converted records are
validated without rewriting their schedules, allocations, receipts or audits.
Historical gaps and extended schedules are retained. Skipped records are reconsidered
on future runs after a legitimate reviewed correction.

Exit status is 0 when there are no invalid courses or financial skips; deleted/missing
lead exclusions alone do not cause failure. Exit status 1 can mean partial success:
inspect the SUMMARY and individual reasons. Unexpected failures abort instead of
being classified as record-level skips. Summary `completed: false` identifies an
aborted run when the database is available to print its summary.

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

### Linux VPS rollout for the active-record conversion fix

This fix changes only API code, tests and this documentation. Production migrations
have already run: do not modify/rerun them, seed data, restore a database, or rebuild
the frontend just for this fix. First commit and push the reviewed changes from the
development machine. Replace `/var/www/school-crm` below with the actual existing
repository parent containing all three app folders. Use a maintenance window and
take an external database/upload backup first. Pause other writers/queue workers
through the VPS's existing process manager as well as web access.

```bash
cd /var/www/school-crm
git status --short
# Stop if there are unexplained tracked server edits; do not reset or discard them.
php scl-api/artisan down
php scl-dashboard/artisan down
git pull --ff-only origin main
cd scl-api
composer install --no-dev --optimize-autoloader --no-interaction
php artisan optimize:clear
set -o pipefail
php artisan fees:convert-first-period | tee "$HOME/first-period-preview.log"
```

Review the full preview and SUMMARY. Financial skips do not block other valid
admissions; do not restore deleted records or move their payments to remove skips.
Only after accepting the proposed eligible conversions:

```bash
php artisan fees:convert-first-period --apply | tee "$HOME/first-period-apply.log"
```

Check SUMMARY `completed`, before/after payment counts and amounts, individual
skips, unchanged paid statuses, sample receipts and fee allocations. Payment totals
must match the actual production baseline measured by the command (not hardcoded
audit examples). Keep maintenance enabled if an unexpected failure occurs.
When verification is satisfactory, resume your paused workers and web access:

```bash
php artisan up
cd ../scl-dashboard
php artisan up
```

Run local verification using `php vendor/bin/phpunit --filter FirstPeriod` from
`scl-api`; `phpunit.xml` uses isolated SQLite `:memory:`. Never point the test suite
at production. The existing future-period/course editing rules are unchanged;
unconverted records retain their existing editing restrictions until reviewed.
