
# Webisdom CRM

A web-based Customer Relationship Management (CRM) system designed to manage leads, organize customer information, and streamline sales follow-ups through a centralized platform.

## Overview

Webisdom CRM aims to simplify lead management and improve team productivity by providing a structured workflow for handling customer inquiries, tracking lead progress, and coordinating follow-up activities.

## Features

- **Lead Management** — Organize and manage customer leads.
- **Lead Assignment** — Support assignment of leads to the appropriate team members.
- **Customer Information** — Maintain relevant lead and customer details.
- **Follow-up Management** — Help teams track lead progress and follow-up activities.
- **Email Integration** — Support email notifications and communication workflows where configured.
- **Database Integration** — Store application data using MySQL.
- **Authentication and Access Control** — Configure secure access according to the application's implemented permissions.
- **Client Categorization & Directory** — Segment and track leads by relationship stage (Prospect, Customer, VIP, Partner) and engagement temperature (Hot, Warm, Cold).
- **Web-Based Interface** — Access the CRM through a supported web browser.

> Note: Features depend on the modules implemented and configured in the current version of the project.

## Technology Stack

- **Backend:** PHP
- **Database:** MySQL
- **Frontend:** HTML, CSS, JavaScript
- **Local Development:** XAMPP or another PHP-compatible server
- **Version Control:** Git and GitHub

## Project Structure

```text
crm-webisdom/
├── assets/              # Stylesheets, scripts, images and other assets
├── config/              # Application configuration, if present
├── includes/            # Shared PHP components, if present
├── uploads/             # User uploads, if supported
├── index.php            # Application entry point, if applicable
├── .gitignore           # Files excluded from version control
├── README.md            # Project documentation
└── ...
```

The structure above is illustrative. Actual directories and entry-point filenames may differ in your project.

## Requirements

Before running the project locally, install:

- PHP (a version compatible with the project)
- MySQL or MariaDB
- Apache or another compatible web server
- A modern web browser
- Git (optional, for cloning the repository)

XAMPP is a convenient option because it bundles Apache, PHP, and MariaDB/MySQL-compatible database tooling.

## Installation and Setup

### 1. Clone the Repository

```bash
git clone https://github.com/YOUR-USERNAME/crm-webisdom.git
cd crm-webisdom
```

Replace `YOUR-USERNAME` with your GitHub username.

Alternatively, download the repository as a ZIP file and extract it.

### 2. Set Up the Local Server

If you're using XAMPP:

1. Copy the project folder into the `htdocs` directory.
2. Start Apache and MySQL from the XAMPP Control Panel.
3. Open phpMyAdmin in your browser.

Example project location:

```text
C:\xampp\htdocs\crm-webisdom
```

### 3. Create the Database

1. Open `http://localhost/phpmyadmin`.
2. Create a database named `webisdom_crm`, or use the database name expected by your application.
3. Import the project's database schema or SQL setup file if one is provided.

**Important:** A database schema is required to create the necessary tables. Use a database export or schema supplied with the project. Do not import production customer data into a local development database.

### 4. Configure Environment Variables

Create a local `.env` file or configure the application's existing environment settings according to its implementation.

Example configuration:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=webisdom_crm
DB_USER=root
DB_PASSWORD=

APP_TIMEZONE=Asia/Kolkata
```

Use the database credentials appropriate for your environment.

If the application uses `env.php` or another PHP configuration file instead of `.env`, configure that file according to the existing code.

**Security:** Never commit real passwords, OAuth client secrets, API keys, or SMTP credentials to GitHub.

### 5. Run the Application

Start Apache and MySQL, then open the application's entry URL in your browser.

For example:

```text
http://localhost/crm-webisdom/
```

If the application uses a different entry point or requires additional configuration, follow the project's existing routing and setup requirements.

## Configuration Checklist

Before using the CRM, verify the following:

- [ ] Database connection is configured.
- [ ] Required database tables have been created.
- [ ] Application credentials are stored securely.
- [ ] Authentication and authorization work as expected.
- [ ] Email settings are configured if email functionality is enabled.
- [ ] Required PHP extensions are installed.
- [ ] File and directory permissions are configured appropriately.

## Deployment

To deploy the CRM to a hosting provider:

1. Upload the application files to the correct web root.
2. Create a database and database user.
3. Import the required schema.
4. Configure production database credentials and environment settings.
5. Configure HTTPS and appropriate file permissions.
6. Test authentication, lead management, database operations, and email functionality.
7. Verify that environment files, backups, logs, and sensitive data are not publicly accessible.

Deployment requirements vary by hosting provider and application configuration.

## Per-User SMTP Configuration (Personal Mailboxes)

Webisdom CRM supports per-user mailbox credentials. Each CRM consultant sends outreach and follow-up emails through **their own mailbox** rather than a single shared company SMTP login.

### 1. Generating the Encryption Key

SMTP passwords are encrypted in the database using **AES-256-GCM**.
Generate a 32-byte encryption key (64 hex characters) using PHP:

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

Copy the generated 64-character hex string and add it to your `.env` file:

```env
SMTP_ENCRYPTION_KEY=your_64_character_hex_key_here
```

### 2. User Setup: My Email Settings

Every CRM user configures their personal mailbox from the CRM sidebar:
1. Navigate to **Account** -> **My Email Settings** (`/admin/my_email_settings.php`).
2. Select your provider preset (Gmail, Outlook/Office365, Zoho, or Custom).
3. Fill in:
   - **Sender Name**: Name displayed to recipients.
   - **Sender Email**: Your corporate email address.
   - **SMTP Host & Port**: Auto-filled (e.g. `smtp.gmail.com:587` with TLS).
   - **SMTP Username**: Your full email address.
   - **SMTP Password**: 16-character **App Password** (see below).
   - **Signature (optional)**: Your custom corporate sign-off.
4. Click **Test Connection** to authenticate with the server.
5. Click **Save & Verify Settings** (or **Send Test Email to Myself**).

### 3. How Users Set Up Gmail / Google Workspace App Passwords

Google accounts with 2-Step Verification require an **App Password** for SMTP:
1. Go to your [Google Account Security Settings](https://myaccount.google.com/security).
2. Under "How you sign in to Google", ensure **2-Step Verification** is turned ON.
3. Search for or click on **App passwords** (or go to `https://myaccount.google.com/apppasswords`).
4. Enter an application name: `Webisdom CRM`.
5. Click **Create**. Google will display a 16-character code (e.g. `abcd efgh ijkl mnop`).
6. Copy and paste the 16 characters into the CRM password field. (Spaces are stripped automatically).

### 4. How Users Set Up Microsoft 365 / Outlook App Passwords

1. Go to your [Microsoft Account Security page](https://account.microsoft.com/security) or Office 365 portal.
2. Select **Advanced security options** -> **App passwords**.
3. Click **Create a new app password**.
4. Copy the generated password into Webisdom CRM.

### 5. Security & Safety Controls

- **Zero Plain-Text Storage**: Passwords are encrypted with AES-256-GCM. Stored format includes random 12-byte IV and 16-byte authentication tag.
- **In-Memory Decryption Only**: Passwords are only decrypted in memory during the SMTP connection and immediately discarded.
- **Masked Password Field**: Stored passwords are never sent back to the browser; fields display `••••••••`.
- **Log Redaction**: SMTP passwords, authentication hashes, and raw debug strings are completely redacted from logs.
- **Admin Privacy**: CRM Admins can see whether a user's mailbox is verified and can reset it, but cannot view or read other users' passwords.
- **Per-User Rate Limits**: Outbound emails are rate-limited per user (default: 20 emails/hour, 100 emails/day) to safeguard mailbox reputation.
- **Header Injection Protection**: All headers (To, From, Subject) are sanitized against CRLF injection attacks.

## Security Best Practices

- Keep secrets and environment-specific configuration out of version control.
- Use prepared statements for database queries.
- Hash passwords with PHP's `password_hash()` and verify them with `password_verify()`.
- Validate and sanitize user input appropriately.
- Enforce server-side authorization checks.
- Protect state-changing requests against CSRF where applicable.
- Validate uploaded files and restrict executable uploads.
- Use HTTPS in production.
- Back up the database securely and restrict access to backups.

## Troubleshooting

**Database connection fails**
- Verify the database server is running.
- Check the database name, username, password, host, and port.
- Confirm that the configured database user has the necessary permissions.

**Application displays a blank page**
- Check PHP and Apache error logs.
- Verify required PHP extensions and configuration files.
- Enable error reporting only in a local development environment.

**Login or email functionality fails**
- Check the authentication configuration and database records.
- Verify SMTP or email provider settings when email functionality is enabled.
- Review server logs without exposing credentials.

## Contributing

1. Fork the repository.
2. Create a feature branch.
3. Make and test your changes.
4. Commit your changes with a descriptive message.
5. Open a pull request describing the changes.

## License

No license has been specified yet. All rights remain with the copyright holder unless a license is added to this repository.

## Maintainer

**Webisdom CRM**

Repository: `crm-webisdom`
