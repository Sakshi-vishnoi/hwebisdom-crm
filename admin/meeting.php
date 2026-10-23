<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_sales_access();
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/google_calendar.php';


/*
|--------------------------------------------------------------------------
| BASIC DATA
|--------------------------------------------------------------------------
*/

$id = (int)($_GET['id'] ?? 0);
$leadId = (int)($_GET['lead_id'] ?? 0);
$meeting = null;

if ($id) {
    $stmt = $pdo->prepare(
        'SELECT m.*,
                l.name AS lead_name,
                l.company,
                l.email AS lead_email
         FROM meetings m
         JOIN leads l ON l.id = m.lead_id
         WHERE m.id = ?'
    );

    $stmt->execute([$id]);
    $meeting = $stmt->fetch();

    if (!$meeting) {
        redirect('meetings.php');
    }

    $leadId = (int)$meeting['lead_id'];
}

if ($leadId < 1) {
    redirect('meetings.php');
}

$lead = assert_lead_access($pdo, $leadId);
$canEditLead = can_edit_lead($pdo, $lead);

$users = $pdo->query(
    'SELECT id, username, email, role
     FROM users
     ORDER BY username'
)->fetchAll();


/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';


    /*
    |--------------------------------------------------------------------------
    | MANUALLY GENERATE GOOGLE MEET FOR EXISTING EVENT
    |--------------------------------------------------------------------------
    |
    | This is only a fallback for an existing Calendar event that somehow
    | does not have a Meet link.
    |
    */

    if ($action === 'generate_google_meet') {

        if (!$id) {
            flash(
                'error',
                'Save the meeting first, then generate Google Meet.'
            );

            redirect(
                'meeting.php?lead_id=' . $leadId
            );
        }

        if (!$canEditLead) {
            http_response_code(403);
            exit('Read-only access.');
        }

        $stmt = $pdo->prepare(
            'SELECT *
             FROM meetings
             WHERE id = ?'
        );

        $stmt->execute([$id]);
        $currentMeeting = $stmt->fetch();

        if (!$currentMeeting) {
            flash(
                'error',
                'Meeting not found.'
            );

            redirect('meetings.php');
        }

        if (!google_is_configured()) {
            flash(
                'error',
                'Google Calendar is not configured.'
            );

            redirect(
                'meeting.php?id=' . $id
            );
        }

        /*
         * If an event exists, use it.
         */
        $calendarId =
            $currentMeeting['google_calendar_id']
            ?: (google_config()['calendar_id'] ?? 'primary');

        $eventId =
            $currentMeeting['google_event_id'] ?? '';

        if (!$eventId) {

            flash(
                'error',
                'This meeting does not have a Google Calendar event. Save the meeting again to create one.'
            );

            redirect(
                'meeting.php?id=' . $id
            );
        }

        /*
         * Create a fresh Meet space.
         */
        [
            $meetOk,
            $meetUrl,
            $spaceName,
            $meetError
        ] = google_create_meet_space(
            $pdo,
            (int)current_user()['id']
        );

        if (!$meetOk) {

            log_activity(
                $pdo,
                $leadId,
                current_user()['id'],
                'Google Meet',
                'Google Meet generation failed: ' .
                $meetError
            );

            flash(
                'error',
                $meetError
            );

            redirect(
                'meeting.php?id=' . $id
            );
        }

        /*
         * Attach Meet URL to existing Calendar event.
         */
        $calendarPayload = [
            'location' => $meetUrl,
        ];

        $existingDescription =
            trim(
                (string)(
                    $currentMeeting['description']
                    ?? ''
                )
            );

        if ($existingDescription !== '') {
            $existingDescription .= "\n\n";
        }

        $existingDescription .=
            'Google Meet: ' . $meetUrl;

        $calendarPayload['description'] =
            $existingDescription;

        [
            $calendarOk,
            $calendarError
        ] = google_update_calendar_event(
            $pdo,
            (int)current_user()['id'],
            $calendarId,
            $eventId,
            $calendarPayload
        );

        if (!$calendarOk) {

            log_activity(
                $pdo,
                $leadId,
                current_user()['id'],
                'Google Meet',
                'Meet was created but Calendar update failed: ' .
                $calendarError
            );

            flash(
                'error',
                'Meet was created, but Calendar could not be updated: ' .
                $calendarError
            );

            redirect(
                'meeting.php?id=' . $id
            );
        }

        /*
         * Save link in CRM.
         */
        $stmt = $pdo->prepare(
            'UPDATE meetings
             SET meeting_link = ?,
                 google_meet_link = ?,
                 google_calendar_id = ?
             WHERE id = ?'
        );

        $stmt->execute([
            $meetUrl,
            $meetUrl,
            $calendarId,
            $id
        ]);

        log_activity(
            $pdo,
            $leadId,
            current_user()['id'],
            'Google Meet',
            'Google Meet link generated and attached to the Calendar event: ' .
            $meetUrl
        );

        flash(
            'success',
            'Google Meet link generated successfully: ' .
            $meetUrl
        );

        // Show the same top-of-page confirmation used elsewhere in the CRM.
        // Keep any existing Google Calendar/Meet error flash as well, so the
        // user can see that the CRM meeting was saved even if integration failed.
        flash(
            'success',
            $isNew
                ? 'Meeting created successfully.'
                : 'Meeting updated successfully.'
        );

        redirect(
            'meeting.php?id=' . $id
        );
    }


    /*
    |--------------------------------------------------------------------------
    | NORMAL SAVE
    |--------------------------------------------------------------------------
    */

    if (!$canEditLead) {
        http_response_code(403);
        exit('Read-only access.');
    }


    $isNew = !$id;

    $title =
        trim(
            $_POST['title'] ?? ''
        );

    $number =
        (int)(
            $_POST['meeting_number']
            ?? 1
        );

    $date =
        $_POST['meeting_date']
        ?? '';

    $start =
        $_POST['start_time']
        ?? '';

    $end =
        trim(
            $_POST['end_time']
            ?? ''
        );

    $end =
        $end !== ''
            ? $end
            : null;

    $link =
        trim(
            $_POST['meeting_link']
            ?? ''
        );

    $location =
        trim(
            $_POST['location']
            ?? ''
        );

    $description =
        trim(
            $_POST['description']
            ?? ''
        );

    $status =
        $_POST['status']
        ?? 'Scheduled';


    /*
    |--------------------------------------------------------------------------
    | PARTICIPANTS
    |--------------------------------------------------------------------------
    */

    $participantIds =
        array_values(
            array_filter(
                array_map(
                    'intval',
                    $_POST['participant_ids'] ?? []
                ),
                fn($value) => $value > 0
            )
        );

    $extraEmails =
        trim(
            $_POST['extra_emails']
            ?? ''
        );


    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if (
        $title === '' ||
        !in_array(
            $number,
            [1, 2],
            true
        ) ||
        !$date ||
        !$start
    ) {

        flash(
            'error',
            'Meeting title, number, date and start time are required.'
        );

    } elseif (
        !in_array(
            $status,
            [
                'Scheduled',
                'Rescheduled',
                'Done',
                'Cancelled'
            ],
            true
        )
    ) {

        flash(
            'error',
            'Invalid meeting status.'
        );

    } else {

        /*
        |--------------------------------------------------------------------------
        | SAVE CRM MEETING FIRST
        |--------------------------------------------------------------------------
        */

        if (!$id) {

            $stmt = $pdo->prepare(
                'INSERT INTO meetings
                (
                    lead_id,
                    meeting_number,
                    title,
                    meeting_date,
                    start_time,
                    end_time,
                    meeting_link,
                    location,
                    description,
                    status,
                    created_by
                )
                VALUES
                (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )'
            );

            $stmt->execute([
                $leadId,
                $number,
                $title,
                $date,
                $start,
                $end,
                $link,
                $location,
                $description,
                $status,
                current_user()['id']
            ]);

            $id =
                (int)$pdo->lastInsertId();

        } else {

            $stmt = $pdo->prepare(
                'UPDATE meetings
                 SET meeting_number = ?,
                     title = ?,
                     meeting_date = ?,
                     start_time = ?,
                     end_time = ?,
                     meeting_link = ?,
                     location = ?,
                     description = ?,
                     status = ?
                 WHERE id = ?'
            );

            $stmt->execute([
                $number,
                $title,
                $date,
                $start,
                $end,
                $link,
                $location,
                $description,
                $status,
                $id
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | PARTICIPANTS
        |--------------------------------------------------------------------------
        */

        $pdo->prepare(
            'DELETE FROM meeting_participants
             WHERE meeting_id = ?'
        )->execute([$id]);

        $emailList = [];

        $insertParticipant =
            $pdo->prepare(
                'INSERT INTO meeting_participants
                (
                    meeting_id,
                    user_id,
                    email
                )
                VALUES (?, ?, ?)'
            );


        foreach ($participantIds as $participantId) {

            $stmt = $pdo->prepare(
                'SELECT email
                 FROM users
                 WHERE id = ?'
            );

            $stmt->execute([
                $participantId
            ]);

            $email =
                $stmt->fetchColumn();

            $insertParticipant->execute([
                $id,
                $participantId,
                $email ?: null
            ]);

            if (
                $email &&
                filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                $emailList[] = $email;
            }
        }


        /*
         * Additional emails.
         */
        foreach (
            preg_split(
                '/[,;\s]+/',
                $extraEmails,
                -1,
                PREG_SPLIT_NO_EMPTY
            )
            as $email
        ) {

            if (
                filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {

                $insertParticipant->execute([
                    $id,
                    null,
                    $email
                ]);

                $emailList[] =
                    $email;
            }
        }


        /*
         * Always include lead email.
         */
        if (
            !empty($lead['email']) &&
            filter_var(
                $lead['email'],
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $emailList[] =
                $lead['email'];
        }


        /*
        |--------------------------------------------------------------------------
        | REMOVE DUPLICATE EMAILS
        |--------------------------------------------------------------------------
        */

        $emailList =
            array_values(
                array_unique(
                    array_filter(
                        $emailList,
                        function ($email) {
                            return filter_var(
                                $email,
                                FILTER_VALIDATE_EMAIL
                            );
                        }
                    )
                )
            );


        /*
        |--------------------------------------------------------------------------
        | READ CURRENT GOOGLE DATA
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare(
            'SELECT *
             FROM meetings
             WHERE id = ?'
        );

        $stmt->execute([
            $id
        ]);

        $savedMeeting =
            $stmt->fetch();


        $googleEventId =
            $savedMeeting['google_event_id']
            ?? null;

        $googleMeetLink =
            $savedMeeting['google_meet_link']
            ?? null;

        $googleCalendarId =
            $savedMeeting['google_calendar_id']
            ?? null;


        /*
        |--------------------------------------------------------------------------
        | GOOGLE CALENDAR + GOOGLE MEET
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | We create the Google event whenever the CRM meeting does not
        | already have a Google event.
        |
        | This works for BOTH:
        |
        | - new meetings
        | - existing CRM meetings without Google event
        |
        */

        /*
         * Email invitations must not be sent until the Google
         * Meet + Calendar event has been created successfully.
         *
         * If Google is configured for this CRM, a missing Google
         * event means the invitation is not ready yet.
         */
        $googleReadyForEmail = true;

        if (
            google_is_configured() &&
            empty($googleEventId)
        ) {

            $googleMeeting = [

                'id' =>
                    $id,

                'title' =>
                    $title,

                'meeting_date' =>
                    $date,

                'start_time' =>
                    $start,

                'end_time' =>
                    $end,

                'description' =>
                    $description,

                'location' =>
                    $location,

            ];


            /*
             * Create:
             *
             * 1. Meet Space
             * 2. Calendar Event
             */
            [
                $googleOk,
                $newGoogleEventId,
                $newGoogleMeetLink,
                $googleError
            ] = google_create_meeting(
                $pdo,
                (int)current_user()['id'],
                $googleMeeting,
                $lead,
                $emailList
            );


            if ($googleOk) {

                $googleEventId =
                    $newGoogleEventId;

                $googleMeetLink =
                    $newGoogleMeetLink;

                $googleCalendarId =
                    google_config()['calendar_id']
                    ?? 'primary';


                /*
                 * The Meet URL becomes the CRM meeting link.
                 */
                $link =
                    $googleMeetLink;


                /*
                 * Save Google data.
                 */
                $stmt = $pdo->prepare(
                    'UPDATE meetings
                     SET meeting_link = ?,
                         google_event_id = ?,
                         google_meet_link = ?,
                         google_calendar_id = ?
                     WHERE id = ?'
                );

                $stmt->execute([
                    $link,
                    $googleEventId,
                    $googleMeetLink,
                    $googleCalendarId,
                    $id
                ]);


                // error_log(
                //     'CRM GOOGLE MEETING SUCCESS: ' .
                //     'meeting_id=' . $id .
                //     ' event_id=' . $googleEventId .
                //     ' meet_link=' . $googleMeetLink .
                //     ' calendar_id=' . $googleCalendarId
                // );


                log_activity(
                    $pdo,
                    $leadId,
                    current_user()['id'],
                    'Google Meet',
                    'Google Meet created: ' .
                    $googleMeetLink
                );


            } else {

                $safeGoogleError =
                    (string)(
                        $googleError
                        ?: 'Unknown Google integration error.'
                    );


                // error_log(
                //     'CRM GOOGLE MEETING CREATE FAILED: ' .
                //     'meeting_id=' . $id .
                //     ' error=' . $safeGoogleError
                // );


                log_activity(
                    $pdo,
                    $leadId,
                    current_user()['id'],
                    'Google Meet',
                    'Google Meet/Calendar creation failed: ' .
                    $safeGoogleError
                );


                /*
                 * Don't stop CRM meeting creation.
                 *
                 * The meeting is already saved locally.
                 */
                $googleReadyForEmail = false;

                flash(
                    'error',
                    'CRM meeting saved, but Google Calendar/Meet failed: ' .
                    $safeGoogleError
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | PIPELINE
        |--------------------------------------------------------------------------
        */

        $stageName =
            'Meetings Scheduled';

        $stageStmt =
            $pdo->prepare(
                'SELECT id
                 FROM pipeline_stages
                 WHERE name = ?
                 LIMIT 1'
            );

        $stageStmt->execute([
            $stageName
        ]);

        $stageId =
            (int)$stageStmt->fetchColumn();


        $stageSubcategory =
            match ($status) {

                'Rescheduled' =>
                    'Rescheduled',

                'Done' =>
                    'Done',

                'Cancelled' =>
                    'Cancelled',

                default =>
                    null
            };


        if ($stageId) {

            $stmt = $pdo->prepare(
                "
                UPDATE leads
                SET
                    stage_id = ?,
                    stage_subcategory = ?,
                    stage_comments = ?,
                    status = 'Meetings Scheduled'
                WHERE id = ?
                AND COALESCE(stage_id, 0) NOT IN
                (
                    SELECT id
                    FROM pipeline_stages
                    WHERE name IN
                    (
                        'Collateral Document Shared with client',
                        'Under Negotiation',
                        'Converted/Won'
                    )
                )
                "
            );

            $stmt->execute([
                $stageId,
                $stageSubcategory,
                $description !== ''
                    ? $description
                    : null,
                $leadId
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | ACTIVITY
        |--------------------------------------------------------------------------
        */

        log_activity(
            $pdo,
            $leadId,
            current_user()['id'],
            'Meeting',
            $title .
            ' scheduled for ' .
            format_day($date) .
            ' at ' .
            date(
                'h:i A',
                strtotime($start)
            ) .
            '.'
        );


        if ($status === 'Done') {

            log_activity(
                $pdo,
                $leadId,
                current_user()['id'],
                'Meeting',
                'Meeting marked completed.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | EMAIL INVITATIONS
        |--------------------------------------------------------------------------
        */

        $cfg =
            require __DIR__ .
            '/../config/mail.php';

        $mailResults = [];

        /*
         * Re-read the final saved meeting.
         */
        $stmt = $pdo->prepare(
            'SELECT *
             FROM meetings
             WHERE id = ?'
        );

        $stmt->execute([
            $id
        ]);

        $saved =
            $stmt->fetch();


        $leadLabel =
            $lead['company']
            ?: $lead['name'];


        /*
         * Only send automatic email for a NEW CRM meeting.
         */
        if ($isNew && $googleReadyForEmail) {

            foreach (
                array_unique($emailList)
                as $to
            ) {

                /*
                 * Read the final database values.
                 *
                 * This guarantees the Meet URL generated above
                 * is what goes into the email.
                 */
                $debugStmt =
                    $pdo->prepare(
                        'SELECT
                            google_event_id,
                            google_meet_link,
                            meeting_link,
                            google_calendar_id
                         FROM meetings
                         WHERE id = ?'
                    );

                $debugStmt->execute([
                    $id
                ]);

                $debugRow =
                    $debugStmt->fetch()
                    ?: [];


                $mailLink =
                    trim(
                        (string)(
                            $debugRow[
                                'google_meet_link'
                            ] ?? ''
                        )
                    );


                if ($mailLink === '') {

                    $mailLink =
                        trim(
                            (string)(
                                $debugRow[
                                    'meeting_link'
                                ] ?? ''
                            )
                        );
                }


                if ($mailLink === '') {
                    $mailLink = $link;
                }

                /*
                 * Never send a new-meeting invitation without a
                 * usable meeting link.
                 */
                if ($mailLink === '') {
                    $mailResults[] =
                        $to . ': skipped - meeting link was not generated';

                    continue;
                }


                $subject =
                    'Meeting Invitation - ' .
                    $title;


                /*
                 * Email HTML.
                 */
                $html =
                    '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;background:#ffffff;padding:25px;border:1px solid #e5e7eb;border-radius:10px">' .
                    '<h2 style="color:#1d4ed8">Meeting Invitation</h2>' .

                    '<p>Hello ' . e($lead['name']) . ',</p>' .
                    '<p>We would like to invite you for a scheduled meeting. Please find the details below.</p>' .

                    '<p>' .

                    '<strong>Client:</strong> ' .
                    e($leadLabel) .

                    '<br>' .

                    '<strong>Date:</strong> ' .
                    e(format_day($date)) .

                    '<br>' .

                    '<strong>Time:</strong> ' .
                    e(
                        date(
                            'h:i A',
                            strtotime($start)
                        )
                    );


                if ($end) {

                    $html .=
                        ' - ' .
                        e(
                            date(
                                'h:i A',
                                strtotime($end)
                            )
                        );
                }


                $html .=
                    '<br>' .
                    '<strong>Meeting link:</strong> ';


                if ($mailLink) {

                    $html .=
                        '<a href="' .
                        e($mailLink) .
                        '">' .
                        e($mailLink) .
                        '</a>';

                } else {

                    $html .=
                        'Not provided';
                }


                if ($location) {

                    $html .=
                        '<br>' .
                        '<strong>Location:</strong> ' .
                        e($location);
                }


                $html .=
                    '</p>' .

                    '<p>' .
                    nl2br(
                        e($description)
                    ) .
                    '</p>' .

                    '<p>We look forward to connecting with you.</p>' .
                    '<p>Regards,<br>' .
                    '<strong>Webisdom Team</strong>' .
                    '</p>' .
                    '</div>';


                /*
                 * Debug logging.
                 *
                 * No OAuth credentials are logged.
                 */
                // error_log(
                //     sprintf(
                //         'CRM MAIL DEBUG: meeting_id=%d to=%s google_event_id=%s google_meet_link=%s meeting_link=%s selected_mail_link=%s subject=%s',
                //         $id,
                //         $to,
                //         (string)(
                //             $debugRow[
                //                 'google_event_id'
                //             ] ?? ''
                //         ),
                //         (string)(
                //             $debugRow[
                //                 'google_meet_link'
                //             ] ?? ''
                //         ),
                //         (string)(
                //             $debugRow[
                //                 'meeting_link'
                //             ] ?? ''
                //         ),
                //         $mailLink,
                //         $subject
                //     )
                // );


                /*
                 * Generate ICS attachment.
                 */
                $ics =
                    meeting_ics(
                        $saved,
                        $cfg['from_email'],
                        $to
                    );


                /*
                 * Send email.
                 */
                [
                    $mailOk,
                    $mailMessage
                ] = smtp_send(
                    $cfg,
                    $to,
                    $subject,
                    $html,
                    $ics
                );


                if ($mailOk) {

                    $stmt =
                        $pdo->prepare(
                            'INSERT INTO email_messages
                            (
                                lead_id,
                                direction,
                                from_email,
                                to_email,
                                subject,
                                body_text,
                                body_html,
                                received_at
                            )
                            VALUES
                            (
                                ?, ?, ?, ?, ?, ?, ?, NOW()
                            )'
                        );

                    $stmt->execute([
                        $leadId,
                        'outbound',
                        $cfg['from_email'],
                        $to,
                        $subject,
                        strip_tags($html),
                        $html
                    ]);
                }


                $mailResults[] =
                    $mailOk

                        ? $to .
                          ': sent | link=' .
                          ($mailLink ?: 'EMPTY')

                        : $to .
                          ': ' .
                          $mailMessage .
                          ' | link=' .
                          ($mailLink ?: 'EMPTY');
            }
        }


        /*
        |--------------------------------------------------------------------------
        | FINAL SUCCESS MESSAGE
        |--------------------------------------------------------------------------
        */

        // if ($isNew) {




        //     if ($mailResults) {

        //         flash(
        //             'success',
        //             'Meeting saved. ' .
        //             implode(
        //                 ' | ',
        //                 $mailResults
        //             ) .
        //             ''
        //         );

        //     } else {

        //         flash(
        //             'success',
        //             'Meeting saved. Configure SMTP in config/mail.php to send automatic invitations. | ' .
        //             $googleDebug
        //         );
        //     }

        // } else {

        //     /*
        //      * If an old meeting did not have Google information,
        //      * the code above may have created it now.
        //      */
        //     if (
        //         !empty($googleEventId) &&
        //         !empty($googleMeetLink)
        //     ) {

        //         flash(
        //             'success',
        //             'Meeting updated successfully. Google Meet: ' .
        //             $googleMeetLink
        //         );

        //     } else {

        //         flash(
        //             'success',
        //             'Meeting updated successfully.'
        //         );
        //     }
        // }


        // Show a green confirmation after the CRM meeting has been saved.
        // This is separate from any Google Calendar/Meet or email warning.
        flash(
            'success',
            $isNew
                ? 'Meeting was scheduled successfully.'
                : 'Meeting updated successfully.'
        );

        redirect(
            'meeting.php?id=' . $id
        );
    }
}


/*
|--------------------------------------------------------------------------
| LOAD MEETING FOR EDIT
|--------------------------------------------------------------------------
*/

if ($id) {

    $stmt = $pdo->prepare(
        'SELECT m.*,
                l.name AS lead_name,
                l.company,
                l.email AS lead_email
         FROM meetings m
         JOIN leads l ON l.id = m.lead_id
         WHERE m.id = ?'
    );

    $stmt->execute([
        $id
    ]);

    $meeting =
        $stmt->fetch();


    /*
     * Participants.
     */
    $participantStmt =
        $pdo->prepare(
            'SELECT user_id, email
             FROM meeting_participants
             WHERE meeting_id = ?'
        );

    $participantStmt->execute([
        $id
    ]);

    $participants =
        $participantStmt->fetchAll();


    $selected =
        array_map(
            fn($row) => (int)$row['user_id'],
            array_filter(
                $participants,
                fn($row) =>
                    !empty($row['user_id'])
            )
        );


    $extraEmails =
        implode(
            ', ',
            array_filter(
                array_map(
                    fn($row) =>
                        $row['email'],
                    array_filter(
                        $participants,
                        fn($row) =>
                            empty($row['user_id'])
                    )
                )
            )
        );

} else {

    /*
     * New meeting.
     */
    $selected = [
        (int)current_user()['id']
    ];

    $extraEmails = '';
}


/*
|--------------------------------------------------------------------------
| PAGE
|--------------------------------------------------------------------------
*/

$page_title =
    $id
        ? 'Edit Meeting'
        : 'Schedule Meeting';

$current_page =
    'meetings';


require __DIR__ .
    '/../includes/header.php';

?>

<div
    class="section-card form-card"
    style="max-width:1000px"
>

    <div
        class="section-head"
        style="margin:-22px -22px 22px"
    >

        <h2>
            <?=e($page_title)?>
        </h2>

        <a
            class="btn btn-light btn-sm"
            href="lead.php?id=<?=$leadId?>"
        >
            Back to Lead
        </a>

    </div>


    <div class="lead-callout">

        <strong>
            <?=e(
                $lead['company']
                ?: $lead['name']
            )?>
        </strong>

        <span>
            <?=e($lead['email'])?>
            ·
            <?=e($lead['contact'])?>
        </span>

    </div>


    <form method="post">

        <input
            type="hidden"
            name="csrf_token"
            value="<?=e(csrf_token())?>"
        >


        <?php if (!$canEditLead): ?>

            <div class="alert">
                Read-only access: meeting details cannot be changed.
            </div>

        <?php endif; ?>


        <fieldset
            <?= !$canEditLead ? 'disabled' : '' ?>
            style="border:0;padding:0;margin:0"
        >

            <div class="form-grid">


                <!-- Meeting Number -->

                <div class="field">

                    <label>
                        Meeting Number
                    </label>

                    <select
                        class="form-control"
                        name="meeting_number"
                    >

                        <option
                            value="1"
                            <?=($meeting['meeting_number'] ?? 1) == 1 ? 'selected' : ''?>
                        >
                            Meeting 1
                        </option>

                        <option
                            value="2"
                            <?=($meeting['meeting_number'] ?? 1) == 2 ? 'selected' : ''?>
                        >
                            Meeting 2
                        </option>

                    </select>

                </div>


                <!-- Title -->

                <div class="field">

                    <label>
                        Title
                    </label>

                    <input
                        class="form-control"
                        name="title"
                        value="<?=e(
                            $meeting['title']
                            ?? 'B2B Sales Meeting'
                        )?>"
                        required
                    >

                </div>


                <!-- Date -->

                <div class="field">

                    <label>
                        Date
                    </label>

                    <input
                        class="form-control"
                        type="date"
                        name="meeting_date"
                        value="<?=e(
                            $meeting['meeting_date']
                            ?? date('Y-m-d', strtotime('+1 day'))
                        )?>"
                        min="<?=e(date('Y-m-d'))?>"
                        required
                    >

                </div>


                <!-- Start -->

                <div class="field">

                    <label>
                        Start Time
                    </label>

                    <input
                        class="form-control"
                        type="time"
                        name="start_time"
                        value="<?=e(
                            $meeting['start_time']
                            ?? '10:00'
                        )?>"
                        required
                    >

                </div>


                <!-- End -->

                <div class="field">

                    <label>
                        End Time
                    </label>

                    <input
                        class="form-control"
                        type="time"
                        name="end_time"
                        value="<?=e(
                            $meeting['end_time']
                            ?? '11:00'
                        )?>"
                    >

                </div>


                <!-- Status -->

                <div class="field">

                    <label>
                        Status
                    </label>

                    <select
                        class="form-control"
                        name="status"
                    >

                        <?php foreach (
                            [
                                'Scheduled',
                                'Rescheduled',
                                'Done',
                                'Cancelled'
                            ]
                            as $statusOption
                        ): ?>

                            <option
                                <?= $statusOption ===
                                    ($meeting['status'] ?? 'Scheduled')
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                <?=e($statusOption)?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- Google Meet -->

                <div class="field">

                    <label>
                        Google Meet / Meeting Link
                    </label>

                    <input
                        class="form-control"
                        name="meeting_link"
                        placeholder="Automatically generated when Google Calendar is connected"
                        value="<?=e(
                            $meeting['google_meet_link']
                            ?? $meeting['meeting_link']
                            ?? ''
                        )?>"
                    >

                    <small class="muted">

                        Google Meet will be generated automatically
                        when Google Calendar is connected.

                    </small>

                </div>


                <!-- Location -->

                <div class="field">

                    <label>
                        Location
                    </label>

                    <input
                        class="form-control"
                        name="location"
                        value="<?=e(
                            $meeting['location']
                            ?? ''
                        )?>"
                    >

                </div>


                <!-- CRM Participants -->

                <div class="field full">

                    <label>
                        CRM Participants
                    </label>

                    <div class="checkbox-grid">

                        <?php foreach ($users as $user): ?>

                            <label class="check-card">

                                <input
                                    type="checkbox"
                                    name="participant_ids[]"
                                    value="<?=$user['id']?>"
                                    <?=in_array(
                                        (int)$user['id'],
                                        $selected,
                                        true
                                    )
                                        ? 'checked'
                                        : ''
                                    ?>
                                >

                                <span>

                                    <strong>
                                        <?=e(
                                            $user['username']
                                        )?>
                                    </strong>

                                    <small>

                                        <?=e(
                                            role_label(
                                                $user['role']
                                            )
                                        )?>

                                        <?php if ($user['email']): ?>

                                            ·
                                            <?=e(
                                                $user['email']
                                            )?>

                                        <?php else: ?>

                                            · No email set

                                        <?php endif; ?>

                                    </small>

                                </span>

                            </label>

                        <?php endforeach; ?>

                    </div>

                </div>


                <!-- Additional Emails -->

                <div class="field full">

                    <label>
                        Additional Participant Emails
                    </label>

                    <input
                        class="form-control"
                        name="extra_emails"
                        value="<?=e($extraEmails)?>"
                        placeholder="client@example.com, another@example.com"
                    >

                </div>


                <!-- Description -->

                <div class="field full">

                    <label>
                        Description / Agenda
                    </label>

                    <textarea
                        class="form-control textarea"
                        name="description"
                        placeholder="Requirements, business needs, scope, agenda..."
                    ><?=e(
                        $meeting['description']
                        ?? ''
                    )?></textarea>

                </div>


            </div>

        </fieldset>


        <div class="form-actions">


            <?php if ($canEditLead): ?>

                <button
                    class="btn btn-primary"
                    type="submit"
                >
                    Save Meeting & Send Invitations
                </button>

            <?php endif; ?>


            <?php
            /*
             * Show manual Meet generation only when:
             *
             * - meeting exists
             * - Calendar event exists
             * - Meet link doesn't exist
             */
            ?>

            <?php if (
                $id &&
                !empty(
                    $meeting['google_event_id']
                ) &&
                empty(
                    $meeting['google_meet_link']
                )
            ): ?>

                <button
                    class="btn btn-light"
                    type="submit"
                    name="action"
                    value="generate_google_meet"
                >
                    Generate Google Meet Link
                </button>

            <?php endif; ?>


            <a
                class="btn btn-light"
                href="meetings.php"
            >
                Cancel
            </a>

        </div>


        <p class="muted form-help">

            Meetings can only be scheduled for today or a future date.
            When Google Calendar is connected, the Google Meet link and
            Calendar invitation are created before the email is sent.
            Automatic email invitations require SMTP to be enabled in
            <code>config/mail.php</code>.

        </p>

    </form>

</div>

<script>
(function () {
    const form = document.querySelector('form[method="post"]');
    const dateInput = document.querySelector('input[name="meeting_date"]');
    const startInput = document.querySelector('input[name="start_time"]');
    const endInput = document.querySelector('input[name="end_time"]');

    if (!dateInput || !startInput) {
        return;
    }

    /*
     * A new meeting can never be scheduled for a date before today.
     * Today is allowed, but if today is selected the start time must
     * still be in the future.
     */
    const today = new Date();
    const todayString =
        today.getFullYear() + '-' +
        String(today.getMonth() + 1).padStart(2, '0') + '-' +
        String(today.getDate()).padStart(2, '0');

    dateInput.min = todayString;

    function validateMeetingDateTime(showMessage) {
        if (!dateInput.value || !startInput.value) {
            return true;
        }

        const selectedDate = dateInput.value;

        if (selectedDate < todayString) {
            if (showMessage) {
                alert('Please select today or a future date for the meeting.');
                dateInput.focus();
            }
            return false;
        }

        /*
         * If the meeting is today, require a future start time.
         */
        if (selectedDate === todayString) {
            const now = new Date();
            const currentMinutes =
                now.getHours() * 60 + now.getMinutes();

            const parts = startInput.value.split(':');
            const startMinutes =
                (parseInt(parts[0], 10) * 60) +
                parseInt(parts[1], 10);

            if (startMinutes <= currentMinutes) {
                if (showMessage) {
                    alert('For today, the start time must be in the future.');
                    startInput.focus();
                }
                return false;
            }
        }

        if (endInput && endInput.value) {
            const startParts = startInput.value.split(':');
            const endParts = endInput.value.split(':');

            const startMinutes =
                parseInt(startParts[0], 10) * 60 +
                parseInt(startParts[1], 10);

            const endMinutes =
                parseInt(endParts[0], 10) * 60 +
                parseInt(endParts[1], 10);

            if (endMinutes <= startMinutes) {
                if (showMessage) {
                    alert('End time must be later than the start time.');
                    endInput.focus();
                }
                return false;
            }
        }

        return true;
    }

    dateInput.addEventListener('change', function () {
        validateMeetingDateTime(true);
    });

    startInput.addEventListener('change', function () {
        validateMeetingDateTime(true);
    });

    if (endInput) {
        endInput.addEventListener('change', function () {
            validateMeetingDateTime(true);
        });
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            if (!validateMeetingDateTime(true)) {
                event.preventDefault();
                return;
            }

            const submitButton = form.querySelector('button[type="submit"].btn-primary');

            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = '⏳ Sending Invitation...';
                submitButton.style.opacity = '0.7';
                submitButton.style.cursor = 'not-allowed';
            }
        });
    }
})();
</script>

<?php

require __DIR__ .
    '/../includes/footer.php';

?>