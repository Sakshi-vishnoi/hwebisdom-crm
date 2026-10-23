<?php

function google_config(): array
{
    return require __DIR__ . '/../config/google.php';
}

function google_is_configured(): bool
{
    $c = google_config();

    return !empty($c['enabled'])
        && !empty($c['client_id'])
        && !empty($c['client_secret'])
        && !empty($c['redirect_uri'])
        && !empty($c['scope'])
        && !str_starts_with($c['client_id'], 'YOUR_')
        && !str_starts_with($c['client_id'], 'PASTE_');
}


/*
|--------------------------------------------------------------------------
| Google OAuth
|--------------------------------------------------------------------------
*/

function google_auth_url(int $userId): string
{
    $c = google_config();

    $state = base64_encode(json_encode([
        'user_id' => $userId,
        'nonce'   => bin2hex(random_bytes(16)),
    ], JSON_UNESCAPED_SLASHES));

    $_SESSION['google_oauth_state'] = $state;

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => $c['client_id'],
        'redirect_uri'  => $c['redirect_uri'],
        'response_type' => 'code',
        'scope'         => $c['scope'],
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => $state,
    ]);
}


/*
|--------------------------------------------------------------------------
| Generic Google HTTP request
|--------------------------------------------------------------------------
*/

function google_http(
    string $url,
    string $method = 'GET',
    $payload = null,
    ?string $accessToken = null
): array {
    $ch = curl_init($url);

    $headers = [
        'Accept: application/json',
    ];

    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    if ($accessToken) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    if ($payload !== null) {
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
    }

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    $body = (string) $body;
    $json = json_decode($body, true);

    return [
        $status,
        is_array($json) ? $json : [],
        $error,
        $body
    ];
}


/*
|--------------------------------------------------------------------------
| Exchange OAuth authorization code
|--------------------------------------------------------------------------
*/

function google_exchange_code(string $code): array
{
    $c = google_config();

    $ch = curl_init('https://oauth2.googleapis.com/token');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $code,
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'redirect_uri'  => $c['redirect_uri'],
            'grant_type'    => 'authorization_code',
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    $json = json_decode((string) $body, true);

    if (
        $status < 200 ||
        $status >= 300 ||
        empty($json['access_token'])
    ) {
        throw new RuntimeException(
            'Google token exchange failed: ' .
            ($json['error_description'] ?? $error ?: $body)
        );
    }

    return $json;
}

/*
|--------------------------------------------------------------------------
| Get Google account email
|--------------------------------------------------------------------------
*/

function google_get_account_email(string $accessToken): ?string
{
    $ch = curl_init(
        'https://openidconnect.googleapis.com/v1/userinfo'
    );

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    $json = json_decode((string) $body, true);

    if (
        $status < 200 ||
        $status >= 300 ||
        empty($json['email'])
    ) {
        error_log(
            'CRM GOOGLE USERINFO FAILED: http=' .
            $status .
            ' error=' .
            ($json['error_description'] ?? $json['error'] ?? $error ?: $body)
        );

        return null;
    }

    return $json['email'];
}


/*
|--------------------------------------------------------------------------
| Save Google OAuth tokens
|--------------------------------------------------------------------------
*/

function google_save_tokens(
    PDO $pdo,
    int $userId,
    array $tokenData,
    ?string $googleEmail = null
): void {

    $accessToken = $tokenData['access_token'] ?? null;
    $refreshToken = $tokenData['refresh_token'] ?? null;

    $expiresAt = time() +
        (int) ($tokenData['expires_in'] ?? 3600);

    if (empty($accessToken)) {
        throw new RuntimeException(
            'Google did not return an access token.'
        );
    }

    /*
     * Check whether this CRM user already has Google tokens.
     */
    $stmt = $pdo->prepare(
        'SELECT id, refresh_token
         FROM google_tokens
         WHERE user_id = ?
         LIMIT 1'
    );

    $stmt->execute([$userId]);

    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    /*
     * Google may not return a refresh_token when an account
     * authorizes the application again.
     *
     * Therefore preserve the old refresh token if necessary.
     */
    if (
        empty($refreshToken) &&
        !empty($existing['refresh_token'])
    ) {
        $refreshToken = $existing['refresh_token'];
    }

    if ($existing) {

        $stmt = $pdo->prepare(
            'UPDATE google_tokens
             SET access_token = ?,
                 refresh_token = ?,
                 expires_at = ?,
                 google_email = ?
             WHERE user_id = ?'
        );

        $stmt->execute([
            $accessToken,
            $refreshToken,
            $expiresAt,
            $googleEmail,
            $userId
        ]);

    } else {

        $stmt = $pdo->prepare(
            'INSERT INTO google_tokens
             (
                 user_id,
                 access_token,
                 refresh_token,
                 expires_at,
                 google_email
             )
             VALUES (?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $userId,
            $accessToken,
            $refreshToken,
            $expiresAt,
            $googleEmail
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| Refresh / retrieve access token
|--------------------------------------------------------------------------
*/

function google_refresh_token(PDO $pdo, int $userId): ?string
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM google_tokens
         WHERE user_id = ?
         LIMIT 1'
    );

    $stmt->execute([$userId]);

    $token = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$token) {
        return null;
    }

    /*
     * Keep current access token while valid.
     */
    if (
        !empty($token['access_token']) &&
        (
            empty($token['expires_at']) ||
            (int) $token['expires_at'] > time() + 60
        )
    ) {
        return $token['access_token'];
    }

    /*
     * Refresh token required after expiry.
     */
    if (empty($token['refresh_token'])) {
        return null;
    }

    $c = google_config();

    $ch = curl_init('https://oauth2.googleapis.com/token');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'refresh_token' => $token['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $json = json_decode((string) $body, true);

    if (
        $status < 200 ||
        $status >= 300 ||
        empty($json['access_token'])
    ) {
        error_log(
            'CRM GOOGLE TOKEN REFRESH FAILED: ' .
            ($json['error_description'] ?? $json['error'] ?? $body)
        );

        return null;
    }

    $expires = time() + (int) ($json['expires_in'] ?? 3600);

    /*
     * Google normally does not return a new refresh token.
     * Therefore keep the existing refresh_token.
     */
    $pdo->prepare(
        'UPDATE google_tokens
         SET access_token = ?, expires_at = ?
         WHERE user_id = ?'
    )->execute([
        $json['access_token'],
        $expires,
        $userId
    ]);

    return $json['access_token'];
}


function google_access_token(PDO $pdo, int $userId): ?string
{
    return google_refresh_token($pdo, $userId);
}


/*
|--------------------------------------------------------------------------
| Get video link from an existing Calendar event
|--------------------------------------------------------------------------
*/

function google_event_video_link(array $event): ?string
{
    if (
        !empty($event['hangoutLink']) &&
        filter_var($event['hangoutLink'], FILTER_VALIDATE_URL)
    ) {
        return $event['hangoutLink'];
    }

    foreach (
        ($event['conferenceData']['entryPoints'] ?? [])
        as $entryPoint
    ) {
        if (
            ($entryPoint['entryPointType'] ?? '') === 'video' &&
            !empty($entryPoint['uri']) &&
            filter_var($entryPoint['uri'], FILTER_VALIDATE_URL)
        ) {
            return $entryPoint['uri'];
        }
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| Get Google Calendar event
|--------------------------------------------------------------------------
*/

function google_get_event(
    PDO $pdo,
    int $userId,
    string $calendarId,
    string $eventId
): array {
    $token = google_access_token($pdo, $userId);

    if (!$token) {
        return [
            false,
            null,
            'Connect Google Calendar for this CRM user first.'
        ];
    }

    $url =
        'https://www.googleapis.com/calendar/v3/calendars/' .
        rawurlencode($calendarId) .
        '/events/' .
        rawurlencode($eventId);

    [$status, $json, $err, $raw] =
        google_http($url, 'GET', null, $token);

    if ($status === 401) {
        return [
            false,
            null,
            'Google authorization expired. Please reconnect Google Calendar.'
        ];
    }

    if ($status < 200 || $status >= 300) {
        return [
            false,
            null,
            'Google Calendar error: ' .
            ($json['error']['message'] ?? $err ?: $raw)
        ];
    }

    return [
        true,
        $json,
        null
    ];
}


/*
|--------------------------------------------------------------------------
| CREATE GOOGLE MEET SPACE
|--------------------------------------------------------------------------
|
| This is the important part.
|
| POST /v2/spaces
|
| The Meet REST API generates the actual Google Meet URL.
|
*/

function google_create_meet_space(
    PDO $pdo,
    int $userId
): array {
    if (!google_is_configured()) {
        return [
            false,
            null,
            null,
            'Google integration is not configured.'
        ];
    }

    $token = google_access_token($pdo, $userId);

    if (!$token) {
        return [
            false,
            null,
            null,
            'Connect Google Calendar first.'
        ];
    }

    [
        $status,
        $json,
        $err,
        $raw
    ] = google_http(
        'https://meet.googleapis.com/v2/spaces',
        'POST',
        new stdClass(),
        $token
    );

    /*
     * Access token expired.
     */
    if ($status === 401) {

        $pdo->prepare(
            'UPDATE google_tokens
             SET access_token = NULL
             WHERE user_id = ?'
        )->execute([$userId]);

        return [
            false,
            null,
            null,
            'Google authorization expired. Please reconnect Google Calendar.'
        ];
    }

    /*
     * Meet API error.
     */
    if ($status < 200 || $status >= 300) {

        $message =
            $json['error']['message'] ??
            ($err ?: $raw);

        error_log(
            'CRM GOOGLE MEET CREATE FAILED: ' .
            'http=' . $status .
            ' message=' . $message .
            ' raw=' . substr((string) $raw, 0, 1000)
        );

        return [
            false,
            null,
            null,
            'Google Meet API error: ' . $message
        ];
    }

    $spaceName = $json['name'] ?? null;
    $meetingUri = $json['meetingUri'] ?? null;

    // error_log(
    //     sprintf(
    //         'CRM GOOGLE MEET CREATE SUCCESS: http=%d name=%s meetingUri=%s',
    //         $status,
    //         (string) $spaceName,
    //         (string) $meetingUri
    //     )
    // );

    /*
     * Google returned success but no usable meetingUri.
     */
    if (
        !$spaceName ||
        !$meetingUri ||
        !filter_var($meetingUri, FILTER_VALIDATE_URL)
    ) {

        $responseKeys = is_array($json)
            ? implode(', ', array_keys($json))
            : 'non-JSON';

        return [
            false,
            null,
            null,
            'Google Meet API succeeded but no valid meetingUri was returned. ' .
            'Response fields: ' . $responseKeys
        ];
    }

    return [
        true,
        $meetingUri,
        $spaceName,
        null
    ];
}


/*
|--------------------------------------------------------------------------
| Create Meet for an existing Google Calendar event
|--------------------------------------------------------------------------
*/

function google_request_meet_for_event(
    PDO $pdo,
    int $userId,
    string $calendarId,
    string $eventId
): array {

    if (!google_is_configured()) {
        return [
            false,
            null,
            'Google integration is not configured.'
        ];
    }

    /*
     * Create a new Meet space.
     */
    [
        $meetOk,
        $meetUrl,
        $spaceName,
        $meetError
    ] = google_create_meet_space($pdo, $userId);

    if (!$meetOk) {
        return [
            false,
            null,
            $meetError
        ];
    }

    /*
     * Retrieve existing Calendar event.
     */
    [
        $eventOk,
        $event,
        $eventError
    ] = google_get_event(
        $pdo,
        $userId,
        $calendarId,
        $eventId
    );

    if (!$eventOk) {
        return [
            false,
            null,
            $eventError
        ];
    }

    $description =
        (string) ($event['description'] ?? '');

    $marker = 'Google Meet: ' . $meetUrl;

    if (strpos($description, $meetUrl) === false) {

        $description =
            rtrim($description) .
            ($description !== '' ? "\n\n" : '') .
            $marker;
    }

    /*
     * Attach Meet URL to Calendar event.
     */
    $payload = [
        'description' => $description,
        'location'    => $meetUrl,
    ];

    $url =
        'https://www.googleapis.com/calendar/v3/calendars/' .
        rawurlencode($calendarId) .
        '/events/' .
        rawurlencode($eventId) .
        '?sendUpdates=all';

    $token = google_access_token($pdo, $userId);

    if (!$token) {
        return [
            false,
            null,
            'Google authorization expired. Please reconnect Google Calendar.'
        ];
    }

    [
        $status,
        $json,
        $err,
        $raw
    ] = google_http(
        $url,
        'PATCH',
        $payload,
        $token
    );

    if ($status === 401) {
        return [
            false,
            null,
            'Google authorization expired. Please reconnect Google Calendar.'
        ];
    }

    if ($status < 200 || $status >= 300) {

        $message =
            $json['error']['message'] ??
            ($err ?: $raw);

        error_log(
            'CRM GOOGLE CALENDAR MEET UPDATE FAILED: ' .
            'http=' . $status .
            ' message=' . $message
        );

        return [
            false,
            null,
            'Google Calendar update failed: ' . $message
        ];
    }

    // error_log(
    //     sprintf(
    //         'CRM GOOGLE CALENDAR MEET UPDATE SUCCESS: eventId=%s meetUrl=%s',
    //         $eventId,
    //         $meetUrl
    //     )
    // );

    return [
        true,
        $meetUrl,
        null
    ];
}


/*
|--------------------------------------------------------------------------
| CREATE GOOGLE CALENDAR EVENT + GOOGLE MEET
|--------------------------------------------------------------------------
|
| Flow:
|
| CRM meeting
|      ↓
| Google Meet API
|      ↓
| meetingUri
|      ↓
| Google Calendar API
|      ↓
| Calendar event + attendees
|
*/

function google_create_meeting(
    PDO $pdo,
    int $userId,
    array $meeting,
    array $lead,
    array $attendeeEmails = []
): array {

    if (!google_is_configured()) {
        return [
            false,
            null,
            null,
            'Google integration is not configured.'
        ];
    }

    $token = google_access_token($pdo, $userId);

    if (!$token) {
        return [
            false,
            null,
            null,
            'Connect Google Calendar first.'
        ];
    }

    $config = google_config();

    $timezone =
        $config['timezone'] ??
        'Asia/Kolkata';

    /*
     * Build reliable RFC3339 start/end timestamps.
     *
     * HTML <input type="time"> normally sends HH:MM,
     * while MySQL TIME values normally return HH:MM:SS.
     * Normalize both cases so the first save behaves
     * exactly like a subsequent save.
     */
    $meetingDate = trim((string) ($meeting['meeting_date'] ?? ''));
    $startTime   = trim((string) ($meeting['start_time'] ?? ''));
    $endTime     = trim((string) ($meeting['end_time'] ?? ''));

    if ($meetingDate === '' || $startTime === '') {
        return [
            false,
            null,
            null,
            'Meeting date and start time are required.'
        ];
    }

    /*
     * Convert HH:MM to HH:MM:SS.
     */
    if (preg_match('/^\d{2}:\d{2}$/', $startTime)) {
        $startTime .= ':00';
    }

    if (
        $endTime !== '' &&
        preg_match('/^\d{2}:\d{2}$/', $endTime)
    ) {
        $endTime .= ':00';
    }

    /*
     * If no end time was supplied, default to one hour
     * after the start time.
     */
    if ($endTime === '') {
        try {
            $defaultStart = new DateTimeImmutable(
                $meetingDate . ' ' . $startTime,
                new DateTimeZone($timezone)
            );

            $defaultEnd = $defaultStart->modify('+1 hour');

            $endTime = $defaultEnd->format('H:i:s');

        } catch (Throwable $e) {
            return [
                false,
                null,
                null,
                'Invalid meeting date/time: ' . $e->getMessage()
            ];
        }
    }

    /*
     * Validate the complete date/time and convert it to
     * an unambiguous RFC3339 value including the timezone
     * offset, e.g.:
     *
     * 2026-10-15T10:00:00+05:30
     */
    try {
        $timezoneObject = new DateTimeZone($timezone);

        $startDateTime = new DateTimeImmutable(
            $meetingDate . ' ' . $startTime,
            $timezoneObject
        );

        $endDateTime = new DateTimeImmutable(
            $meetingDate . ' ' . $endTime,
            $timezoneObject
        );

        if ($endDateTime <= $startDateTime) {
            return [
                false,
                null,
                null,
                'Meeting end time must be later than the start time.'
            ];
        }

        $start = $startDateTime->format(DateTimeInterface::RFC3339);
        $end   = $endDateTime->format(DateTimeInterface::RFC3339);

    } catch (Throwable $e) {
        return [
            false,
            null,
            null,
            'Invalid meeting date/time: ' . $e->getMessage()
        ];
    }


    /*
     * Build attendee email list.
     */
    $recipientEmails = $attendeeEmails;

    if (
        !empty($lead['email']) &&
        filter_var($lead['email'], FILTER_VALIDATE_EMAIL)
    ) {
        $recipientEmails[] = $lead['email'];
    }

    $recipientEmails = array_values(
        array_unique(
            array_filter(
                array_map(
                    'trim',
                    $recipientEmails
                ),
                static function ($email) {
                    return filter_var(
                        $email,
                        FILTER_VALIDATE_EMAIL
                    );
                }
            )
        )
    );

    $attendees = array_map(
        static function ($email) {
            return [
                'email' => $email
            ];
        },
        $recipientEmails
    );


    /*
     * STEP 1:
     *
     * Create the Google Meet Space.
     */
    [
        $meetOk,
        $meetUrl,
        $spaceName,
        $meetError
    ] = google_create_meet_space(
        $pdo,
        $userId
    );

    if (!$meetOk) {
        return [
            false,
            null,
            null,
            $meetError
        ];
    }


    /*
     * STEP 2:
     *
     * Build Calendar description.
     */
    $description =
        trim(
            (string) (
                $meeting['description'] ?? ''
            )
        );

    $description =
        ($description !== ''
            ? $description . "\n\n"
            : ''
        ) .
        'Google Meet: ' .
        $meetUrl;


    /*
     * STEP 3:
     *
     * Calendar event payload.
     */
    $payload = [
        'summary' => $meeting['title'],

        'description' => $description,

        /*
         * Put the Meet URL directly in Calendar location.
         */
        'location' => $meetUrl,

        'start' => [
            'dateTime' => $start,
            'timeZone' => $timezone,
        ],

        'end' => [
            'dateTime' => $end,
            'timeZone' => $timezone,
        ],

        'attendees' => $attendees,
    ];


    /*
     * STEP 4:
     *
     * Create Calendar event.
     *
     * sendUpdates=all makes Google send
     * Calendar invitations to attendees.
     */
    $calendarId =
        $config['calendar_id'] ??
        'primary';

    $url =
        'https://www.googleapis.com/calendar/v3/calendars/' .
        rawurlencode($calendarId) .
        '/events?sendUpdates=all';


    [
        $status,
        $json,
        $err,
        $raw
    ] = google_http(
        $url,
        'POST',
        $payload,
        $token
    );


    /*
     * Unauthorized.
     */
    if ($status === 401) {

        $pdo->prepare(
            'UPDATE google_tokens
             SET access_token = NULL
             WHERE user_id = ?'
        )->execute([$userId]);

        return [
            false,
            null,
            null,
            'Google authorization expired. Please reconnect Google Calendar.'
        ];
    }


    /*
     * Calendar API error.
     */
    if ($status < 200 || $status >= 300) {

        $message =
            $json['error']['message'] ??
            ($err ?: $raw);

        error_log(
            'CRM GOOGLE CALENDAR CREATE FAILED: ' .
            'http=' . $status .
            ' message=' . $message .
            ' raw=' . substr((string) $raw, 0, 2000)
        );

        return [
            false,
            null,
            null,
            'Google Calendar error: ' . $message
        ];
    }


    /*
     * Success.
     */
    $eventId =
        $json['id'] ??
        null;

    // error_log(
    //     sprintf(
    //         'CRM GOOGLE CALENDAR CREATE SUCCESS: http=%d eventId=%s meetUrl=%s',
    //         $status,
    //         (string) $eventId,
    //         (string) $meetUrl
    //     )
    // );


    if (!$eventId) {
        return [
            false,
            null,
            null,
            'Google Calendar created the event but returned no event ID.'
        ];
    }


    /*
     * Final validation.
     */
    if (
        !$meetUrl ||
        !filter_var(
            $meetUrl,
            FILTER_VALIDATE_URL
        )
    ) {
        return [
            false,
            null,
            null,
            'Google Meet returned an invalid meeting URL.'
        ];
    }


    /*
     * Return:
     *
     * [success, Google event ID, Meet URL, error]
     */
    return [
        true,
        $eventId,
        $meetUrl,
        null
    ];
}