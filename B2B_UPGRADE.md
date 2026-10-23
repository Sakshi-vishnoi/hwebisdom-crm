# B2B CRM Upgrade Guide

## Goal implemented
The CRM follows the supplied meeting-minutes workflow:

Lead received/source recorded → Exploratory Meeting → Meetings Scheduled → Collateral Document Preparation DA Team → Collateral Document Shared with client → Under Negotiation → Converted/Won.

## Main changes
1. `leads` now supports company, lead source and pipeline stage.
2. Existing legacy status is retained for compatibility.
3. Multiple follow-ups are stored in `lead_followups`.
4. Meetings and participants are stored separately.
5. Documents are stored privately under `storage/documents` and downloaded through a protected PHP endpoint.
6. Activities provide a chronological lead history.
7. Negotiations provide final discussion/closure fields.
8. User roles include Admin, B2B Manager, Sales Staff and Closer.
9. Dashboard and Pipeline pages show the seven approved sales stages.
10. Meeting invitations support SMTP plus an iCalendar request.
11. Stage subcategories and meeting comments are stored on each lead.

## Safe upgrade sequence
- Back up the current CRM files.
- Export the existing MySQL database.
- Copy this version to a test/staging folder first.
- Set database credentials in `config/database.php`.
- Run `admin/migrate.php` once.
- Confirm existing leads and users are present.
- Create/assign user email addresses in Users.
- Configure SMTP in `config/mail.php` and set `enabled` to `true` only after testing.
- Test one meeting invitation.
- Test document upload/download.
- Test negotiation and Converted/Won.
- Delete or rename `admin/migrate.php` after migration.

## Legacy lead mapping
- New → New Lead
- Contacted / In Progress / Follow-up → Exploratory Meeting
- Meeting 1 / Meeting 2 → Meetings Scheduled
- Proposal / Documents → Collateral Document Preparation DA Team
- Negotiation → Under Negotiation
- Converted / Won → Converted/Won

The original `status` value remains in `leads.status` so old integrations do not break.
