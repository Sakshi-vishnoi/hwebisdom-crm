# Webisdom B2B CRM — Hostinger deployment

## 1. Create the MySQL database

In Hostinger hPanel → Databases → MySQL Databases:

- Create a database.
- Create a database user.
- Give the user full privileges on that database.
- Record the database name, username, password and host shown by Hostinger.

## 2. Upload the CRM

Upload the contents of this folder into your desired web directory, for example:

`public_html/crm/`

The final URL will be:

`https://YOUR-DOMAIN.com/crm/`

Do not upload a real `.env` from your development machine. Copy `.env.example` to `.env` on the server and enter production values there.

## 3. Configure `.env`

At `public_html/crm/.env`, set:

- `DB_HOST`
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`
- SMTP settings if email is required
- Google OAuth values if Calendar/Meet is required
- a long random `CRM_LEADS_API_KEY`

The root `.htaccess` blocks public access to `.env`.

## 4. Import the database

If this is a new installation, import `database/crm.sql` in phpMyAdmin.

If this is an existing CRM/database, back it up first and use the CRM migration/upgrade flow instead of blindly replacing the existing database.

For the B2B upgrade, open the migration page once after connecting the database:

`https://YOUR-DOMAIN.com/crm/admin/migrate.php`

Then remove or rename `admin/migrate.php` immediately after successful setup.

If Google integration is needed, log in as Admin and run:

`https://YOUR-DOMAIN.com/crm/admin/upgrade_integrations.php`

## 5. PHP settings

Use PHP 8.1+ (8.2/8.3 is preferable if the existing Hostinger account supports it) with:

- PDO MySQL
- cURL
- OpenSSL
- mbstring
- fileinfo
- session

Inbound IMAP sync additionally needs the PHP IMAP extension.

## 6. Storage permissions

`storage/documents/` must be writable by PHP. Keep PHP execution blocked there; the included `.htaccess` does this.

## 7. Google Calendar + Meet

Set the exact redirect URI in Google Cloud to:

`https://YOUR-DOMAIN.com/crm/admin/google_callback.php`

It must exactly match `GOOGLE_REDIRECT_URI` in `.env`.

Enable Google Calendar API and configure the OAuth consent screen/client. Then each CRM user connects their Google account from the CRM.

## 8. HTTPS

Enable Hostinger SSL before using Google OAuth or production logins.

## 9. First login

Open:

`https://YOUR-DOMAIN.com/crm/admin/login.php`

Use the admin account created by the migration page, or an existing user if migrating an existing database.

## 10. Security before launch

- Rotate any database/SMTP credentials that were ever committed to the development ZIP/repository.
- Do not commit `.env` to GitHub.
- Delete/rename `admin/migrate.php` after setup.
- Keep `config/` inaccessible through the web server.
- Keep HTTPS enabled.
- Generate a unique long website-lead API key.
