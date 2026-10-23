<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

require_once __DIR__ . '/../includes/google_calendar.php';

$user = current_user();

/*
|--------------------------------------------------------------------------
| Get Google connection
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare(
    'SELECT google_email
     FROM google_tokens
     WHERE user_id = ?
     LIMIT 1'
);

$stmt->execute([$user['id']]);

$googleEmail = $stmt->fetchColumn();
$isConnected = !empty($googleEmail);

/*
|--------------------------------------------------------------------------
| Disconnect Google
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'disconnect') {

        $delete = $pdo->prepare(
            'DELETE FROM google_tokens WHERE user_id = ?'
        );

        $delete->execute([$user['id']]);

        flash(
            'success',
            'Google Calendar & Meet disconnected successfully.'
        );

        redirect('google_settings.php');
    }
}

$page_title = 'Google Calendar & Meet';
$current_page = 'google';

require __DIR__ . '/../includes/header.php';
?>

<div class="section-card form-card" style="max-width:760px">

    <div class="section-head">

        <div>

            <h2>Google Calendar & Meet</h2>

            <p class="muted">
                Connect your Google account so CRM meetings can
                automatically create Google Calendar events and
                Google Meet links.
            </p>

        </div>

    </div>


    <?php if (!google_is_configured()): ?>

        <div class="alert danger" style="margin-top:18px">

            <strong>Google integration is not configured.</strong>

            <div style="margin-top:6px">

                Please configure the Google OAuth Client ID,
                Client Secret, Redirect URI and scopes in
                <code>config/google.php</code>.

            </div>

        </div>


    <?php elseif ($isConnected): ?>

        <div class="lead-callout" style="margin-top:18px">

            <div>

                <strong>Google account connected</strong>

                <div class="muted" style="margin-top:4px">

                    <?= e($googleEmail) ?>

                </div>

            </div>

        </div>


        <div class="alert success" style="margin-top:16px">

            <strong>Google Calendar & Meet are ready.</strong>

            <div style="margin-top:6px">

                CRM meetings can now create Google Meet spaces,
                Google Calendar events and Calendar invitations.

            </div>

        </div>


        <div style="margin-top:20px">

            <h3 style="margin-bottom:10px">
                Connected services
            </h3>

            <div style="display:flex;flex-direction:column;gap:8px">

                <div>
                    ✓ Google Calendar
                </div>

                <div>
                    ✓ Google Meet
                </div>

                <div>
                    ✓ Calendar invitations
                </div>

            </div>

        </div>


        <form
            method="post"
            style="margin-top:24px"
            onsubmit="return confirm('Disconnect this Google account from the CRM?');"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="disconnect"
            >

            <button
                type="submit"
                class="btn btn-danger"
            >
                Disconnect Google
            </button>

        </form>


    <?php else: ?>

        <div class="alert info" style="margin-top:18px">

            <strong>No Google account connected.</strong>

            <div style="margin-top:6px">

                Connect your Google account to enable automatic
                Google Calendar events and Google Meet links.

            </div>

        </div>


        <div style="margin-top:18px">

            <h3>What will happen after connecting?</h3>

            <ul style="line-height:1.8">

                <li>
                    A Google Meet link can be generated automatically
                    when you create a CRM meeting.
                </li>

                <li>
                    A Google Calendar event will be created.
                </li>

                <li>
                    The generated Meet URL will be saved in the CRM.
                </li>

                <li>
                    Meeting participants can receive Calendar invitations.
                </li>

                <li>
                    The Meet link can be included in the CRM email invitation.
                </li>

            </ul>

        </div>


        <div style="margin-top:22px">

            <a
                class="btn btn-primary"
                href="google_connect.php"
            >
                Connect Google Calendar & Meet
            </a>

        </div>

    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>