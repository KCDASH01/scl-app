# CRM Admin (standalone)

Operator console for the single-company CRM. Session login uses the `admins` table (separate from CRM users).

Migrations for the shared database live here. Run them before using `scl-api`.

```bash
php artisan migrate
```

Then seed from `scl-api`: `php artisan db:seed`.

Default local admin from the original migration (change immediately): `admin@crm.app` / `password`.
