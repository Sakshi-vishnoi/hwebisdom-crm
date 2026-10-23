<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/google_calendar.php';

require_login();

try {

    /*
    |--------------------------------------------------------------------------
    | Google returned an error
    |--------------------------------------------------------------------------
    */

    if (!empty($_GET['error'])) {
        $error = $_GET['error_description']
            ?? $_GET['error'];

        flash(
            'error',
            'Google authorization failed: ' . $error
        );

        redirect('google_settings.php');
    }


    /*
    |--------------------------------------------------------------------------
    | Validate authorization code
    |--------------------------------------------------------------------------
    */

    $code = trim($_GET['code'] ?? '');

    if ($code === '') {
        flash(
            'error',
            'Google did not return an authorization code.'
        );

        redirect('google_settings.php');
    }


    /*
    |--------------------------------------------------------------------------
    | Validate OAuth state
    |--------------------------------------------------------------------------
    */

    $state = $_GET['state'] ?? '';

    if (
        empty($_SESSION['google_oauth_state']) ||
        empty($state) ||
        !hash_equals(
            $_SESSION['google_oauth_state'],
            $state
        )
    ) {
        flash(
            'error',
            'Invalid Google OAuth state. Please try connecting again.'
        );

        redirect('google_settings.php');
    }

    /*
     * State should only be used once.
     */
    unset($_SESSION['google_oauth_state']);


    /*
    |--------------------------------------------------------------------------
    | Decode state
    |--------------------------------------------------------------------------
    */

    $stateData = json_decode(
        base64_decode($state),
        true
    );

    if (
        !is_array($stateData) ||
        empty($stateData['user_id'])
    ) {
        flash(
            'error',
            'Invalid Google OAuth state data.'
        );

        redirect('google_settings.php');
    }


    /*
    |--------------------------------------------------------------------------
    | Make sure the Google account is being connected
    | to the currently logged-in CRM user.
    |--------------------------------------------------------------------------
    */

    $user = current_user();

    $userId = (int) $stateData['user_id'];

    if ((int) $user['id'] !== $userId) {
        flash(
            'error',
            'Google account could not be connected to this CRM user.'
        );

        redirect('google_settings.php');
    }


    /*
    |--------------------------------------------------------------------------
    | Exchange authorization code for Google tokens
    |--------------------------------------------------------------------------
    */

    $tokens = google_exchange_code($code);


    /*
    |--------------------------------------------------------------------------
    | Get Google account email
    |--------------------------------------------------------------------------
    */

    $googleEmail = google_get_account_email(
        $tokens['access_token']
    );


    /*
    |--------------------------------------------------------------------------
    | Save Google tokens
    |--------------------------------------------------------------------------
    */

    google_save_tokens(
        $pdo,
        $userId,
        $tokens,
        $googleEmail
    );


    /*
    |--------------------------------------------------------------------------
    | Success
    |--------------------------------------------------------------------------
    */

    flash(
        'success',
        'Google Calendar & Meet connected successfully.'
    );

    /*
     * IMPORTANT:
     * Your actual page is google_settings.php,
     * NOT google_calendar.php.
     */
    redirect('google_settings.php');


} catch (Throwable $e) {

    error_log(
        'CRM GOOGLE CALLBACK ERROR: ' .
        $e->getMessage()
    );

    flash(
        'error',
        'Google connection failed: ' . $e->getMessage()
    );

    redirect('google_settings.php');
}