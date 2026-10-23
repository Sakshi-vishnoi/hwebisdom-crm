-- Run only if you are not using admin/migrate.php. Safe to run once.
ALTER TABLE leads ADD COLUMN client_category VARCHAR(30) NOT NULL DEFAULT 'Prospect';
ALTER TABLE leads ADD COLUMN client_label VARCHAR(10) NOT NULL DEFAULT 'Cold';
