# Latest CRM Role & Pipeline Update

This build implements the approved CRM role and sales workflow.

## Roles

- Admin: full CRM control, including users, roles, sources and system integrations.
- B2B Manager: full visibility of all leads and sales management; cannot create/delete users or change system administration settings.
- Sales Team: works only on leads assigned to them or created by them.
- QC Member: full sales-data visibility for monitoring/audit; read-only.

## Pipeline

1. New Lead -> Email
2. Meetings Scheduled -> Rescheduled / Cancelled / Done + Comments
3. Collateral Document Preparation DA Team -> Audit PPT / Case studies / Proposal / SoW
4. Collateral Document Shared with client -> Audit PPT / Case studies / SoW
5. Under Negotiation
6. Converted/Won
7. Exploratory Meeting

The seven stages are centralized in `includes/functions.php` and only active approved stages are shown by Create Lead, Edit Lead, Pipeline and dashboard.

## Permissions

- User administration: Admin only.
- Lead deletion/bulk deletion: Admin only.
- Lead creation: Admin, B2B Manager, Sales Team.
- Lead editing: Admin and B2B Manager for all leads; Sales Team for their own leads; QC read-only.
- Meetings, follow-ups, documents and outbound email: Admin, B2B Manager and Sales Team; QC read-only.
- Export/viewing: all sales-access roles.
- QC cannot modify sales records.
- Sales users cannot assign/reassign leads to another user.
- QC is not an eligible lead assignee.

## Database migration

`admin/migrate.php` now:
- Adds the new lead stage subcategory/comment fields when missing.
- Seeds the seven approved stages.
- Deactivates legacy pipeline stages.
- Adds Website Contact Form as a source.
- Converts any legacy `closer` users to `staff` (Sales Team).

Run the migration once on a backup/test copy, then remove or rename `admin/migrate.php` as before.
