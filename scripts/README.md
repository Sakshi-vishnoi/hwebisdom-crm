# Automatic inbound email sync

For Windows/XAMPP, enable the PHP IMAP extension in `C:\xampp\php\php.ini` (remove the leading `;` from the IMAP extension line if present), restart Apache, and test `php -m` for `imap`.

Configure `config/inbox.php`, then create a Windows Task Scheduler task to run:

`C:\xampp\php\php.exe C:\xampp\htdocs\newcrm\scripts\sync_email.php`

Run it every 5–10 minutes for near-real-time inbox capture.
