# Webisdom B2B CRM — UI/UX Update

This build focuses on a professional, responsive CRM interface while preserving the existing PHP/MySQL workflow.

## Updated
- Sidebar navigation redesigned with grouped sections: Sales, Workspace, Management, Account.
- Consistent inline SVG icons; no external icon library required.
- Fixed navigation label wrapping, including Add Lead and Follow-ups.
- Correct active navigation state for Add Lead.
- Cleaner brand area and signed-in user area.
- Improved topbar, breadcrumb, date and user avatar.
- Responsive mobile sidebar with overlay, Escape handling and automatic close.
- Refined cards, tables, filters, forms, badges, buttons, pipeline board, lead detail, timeline and login styles.
- Improved spacing, typography, borders, hover states and focus states.
- Pipeline board corrected to the 8 workflow stages.
- Existing functionality and URLs were preserved.

## Local demo
The uploaded build currently contains a local XAMPP-style `config/database.php` pointing to:
- Host: 127.0.0.1
- Database: newcrm_demo
- User: root
- Password: empty

For production, replace this with your production configuration or environment variables before deployment.

## Validation
All PHP files pass `php -l` syntax validation after the UI changes.
