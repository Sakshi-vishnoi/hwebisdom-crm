# Fresh Webisdom CRM installation (Hostinger)

## Files
- `webisdom_crm_fresh.sql` (provided separately) creates the latest 15-table schema and imports compatible records from both database dumps. Import it only into a newly-created, empty database.
- This ZIP contains the application code and a schema-only `database/crm.sql`; it does not contain production `.env` credentials.

## 1. Create a new database
In hPanel, create a new MySQL database and user, then grant that user all privileges on the new database. Do not delete or overwrite your current database.

## 2. Import SQL
Open phpMyAdmin for the new database, select the empty database, choose **Import**, and upload `webisdom_crm_fresh.sql`. The file does not create or select a database by name.

## 3. Upload application
Upload the contents of this ZIP's `hwebisdom-crm-temp/` directory to your target web directory (for example, `public_html/crm/`).

## 4. Configure `.env`
Copy `.env.example` to `.env` in the application root. Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` using the exact values shown in Hostinger. Generate a new SMTP encryption key using `php -r "echo bin2hex(random_bytes(32));"`. Do not use the placeholder key. Keep `.env` private and never commit it to GitHub.

If you need to decrypt SMTP passwords already stored in `user_smtp_settings`, you must use the exact `SMTP_ENCRYPTION_KEY` that encrypted those values. If you do not have that original key, those stored SMTP passwords cannot be recovered; set them again in CRM.

## 5. Login and integrations
Open `/admin/login.php`. The database dump may contain existing user accounts and password hashes; use an account from the source database. If no usable admin account exists, use the application's documented account recovery/reset process after backing up the database.

Google Calendar/Meet is disabled by default. Set `GOOGLE_ENABLED=true`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and the exact `GOOGLE_REDIRECT_URI` configured in Google Cloud. The redirect URI must match your actual domain and installation path.

## 6. Before going live
- Test login, leads, clients, follow-ups, documents, meetings, SMTP, and Google OAuth on the new database.
- Confirm document upload works and `storage/documents/` is writable by PHP.
- Remove or restrict `admin/migrate.php` after any needed migration has completed.
- Keep HTTPS enabled, rotate any credentials exposed in old development files, and retain a backup of the original database until testing is complete.
