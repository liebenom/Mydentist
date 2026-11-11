<?php
/**
 * Appointment email notifications: received, confirmed, declined, rescheduled,
 * cancelled, emergency-moved, and the 24-hour / 1-hour reminders.
 * Every email carries the booking reference. Only Guest Mode bookings get a QR code
 * (QR is GUEST-only); registered patients use their reference number.
 */

require_once ROOT_DIR . '/includes/qrcode.php';

function appointment_manage_link(array $appt): string
{
    if ($appt['patient_id']) {
        return abs_url('patient/appointments.php');
    }
    return abs_url('guest/manage.php?code=' . urlencode($appt['code']) . '&token=' . $appt['manage_token']);
}

function email_layout(string $heading, string $intro, string $content, string $accent = '#2a759a'): string
{
    $clinic = e(APP_NAME);
    $addr = e(CLINIC_ADDRESS) . ' &middot; ' . e(CLINIC_PHONE);
    return <<<HTML
<!doctype html><html><body style="margin:0;padding:0;background:#e3f1f7;font-family:Arial,Helvetica,sans-serif;color:#1e3a4c">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#e3f1f7;padding:24px 0"><tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden">
<tr><td style="background:{$accent};padding:22px 28px;color:#ffffff">
  <div style="font-size:13px;letter-spacing:.5px;opacity:.9">{$clinic}</div>
  <div style="font-size:22px;font-weight:bold;margin-top:4px">{$heading}</div>
</td></tr>
<tr><td style="padding:26px 28px 8px 28px;font-size:15px;line-height:1.55">{$intro}</td></tr>
<tr><td style="padding:0 28px 24px 28px">{$content}</td></tr>
<tr><td style="background:#f2f8fb;padding:16px 28px;font-size:12px;color:#5f7383;line-height:1.5">
  {$clinic} &middot; {$addr}<br>This is an automated message. Please do not reply directly to this email.
</td></tr>
</table></td></tr></table></body></html>
HTML;
}

function appointment_details_table(array $appt, bool $withQr = true): string
{
    $rows = [];
    if (is_guest_booking($appt)) {
        // reference number exists only for GUEST bookings (no account)
        $rows['Reference no.'] = '<strong style="font-family:monospace;font-size:16px">' . e($appt['code']) . '</strong>';
    }
    $rows += [
        'Patient'       => e($appt['contact_name']),
        'Service'       => e($appt['service_name']) . ' <span style="color:#5f7383">(' . (int) $appt['duration_min'] . ' min)</span>',
        'Dentist'       => e($appt['dentist_name']),
        'Date'          => e(fmt_date($appt['start_at'], 'l, F j, Y')),
        'Time'          => e(fmt_time($appt['start_at']) . ' – ' . fmt_time($appt['end_at'])),
        'Status'        => e(status_label($appt['status'])),
    ];
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #d3e5ee;border-radius:10px;font-size:14px">';
    foreach ($rows as $k => $v) {
        $html .= '<tr><td style="padding:9px 14px;color:#5f7383;width:130px;border-bottom:1px solid #e8f1f6">' . $k . '</td><td style="padding:9px 14px;border-bottom:1px solid #e8f1f6">' . $v . '</td></tr>';
    }
    $html .= '</table>';
    if ($withQr) {
        $html .= '<div style="text-align:center;margin-top:20px"><img src="cid:qrcode" width="170" height="170" alt="QR code ' . e($appt['code']) . '" style="border:1px solid #d3e5ee;border-radius:10px">'
               . '<div style="font-size:13px;color:#5f7383;margin-top:6px">Show this QR code at the front desk for quick check-in.</div></div>';
    } elseif (is_guest_booking($appt)) {
        $html .= '<div style="text-align:center;margin-top:16px;font-size:13px;color:#5f7383">Give your reference number <strong style="font-family:monospace;color:#1e3a4c">' . e($appt['code']) . '</strong> at the front desk for check-in.</div>';
    } else {
        $html .= '<div style="text-align:center;margin-top:16px;font-size:13px;color:#5f7383">Please give your name at the front desk for check-in.</div>';
    }
    return $html;
}

function email_button(string $href, string $label): string
{
    return '<div style="text-align:center;margin-top:22px"><a href="' . e($href) . '" style="display:inline-block;background:#2a759a;color:#ffffff;text-decoration:none;padding:12px 26px;border-radius:8px;font-weight:bold;font-size:14px">' . e($label) . '</a></div>';
}

/**
 * Send an appointment email. $type: received | confirmed | declined | rescheduled |
 * cancelled | emergency_moved | reminder24 | reminder12 | reminder6 | reminder (sent by staff)
 */
function notify(int $appointmentId, string $type, array $extra = []): bool
{
    $appt = find_appointment($appointmentId);
    if (!$appt || empty($appt['contact_email']) || !empty($appt['is_demo'])) {
        return false;   // demo records never send emails
    }
    $name = e(explode(' ', trim($appt['contact_name']))[0]);
    $when = e(fmt_date($appt['start_at'], 'l, F j') . ' at ' . fmt_time($appt['start_at']));
    $manage = appointment_manage_link($appt);
    $note = !empty($extra['note']) ? '<p style="background:#fff8e6;border-left:4px solid #f0ad4e;padding:10px 14px;border-radius:6px">' . nl2br(e($extra['note'])) . '</p>' : '';
    $arrive = '<p style="font-size:14px;color:#3b5566">Please arrive 10 minutes before your schedule. If you can no longer make it, kindly reschedule or cancel so the slot can be given to another patient.</p>';
    $accent = '#2a759a';
    $qr = true;

    $ref = is_guest_booking($appt) ? $appt['code'] : fmt_date($appt['start_at'], 'M j') . ' ' . fmt_time($appt['start_at']);
    switch ($type) {
        case 'received':
            $subject = 'Appointment request received – ' . $ref;
            $heading = 'We received your request';
            $intro = "<p>Hi $name,</p><p>Thank you for booking with us. Your request for <strong>$when</strong> is now <strong>pending</strong>. We will email you again once the clinic confirms it.</p>";
            $after = email_button($manage, 'View or manage appointment');
            break;
        case 'confirmed':
            $subject = 'Appointment confirmed – ' . fmt_date($appt['start_at'], 'M j') . ' ' . fmt_time($appt['start_at']);
            $heading = 'Your appointment is confirmed';
            $intro = "<p>Hi $name,</p><p>Good news! Your appointment on <strong>$when</strong> is <strong>confirmed</strong>.</p>";
            $after = $arrive . email_button($manage, 'View or manage appointment');
            break;
        case 'declined':
            $subject = 'Appointment request could not be accommodated – ' . $ref;
            $heading = 'We could not confirm your request';
            $accent = '#c2410c';
            $qr = false;
            $intro = "<p>Hi $name,</p><p>We are sorry, but the clinic could not accommodate your requested schedule on <strong>$when</strong>. Please book another available slot.</p>";
            $after = email_button(abs_url($appt['patient_id'] ? 'patient/book.php' : 'guest/book.php'), 'Book another schedule');
            break;
        case 'rescheduled':
            $old = !empty($extra['old_start']) ? ' (previously ' . e(fmt_date($extra['old_start'], 'M j') . ' at ' . fmt_time($extra['old_start'])) . ')' : '';
            $subject = 'Appointment rescheduled – ' . fmt_date($appt['start_at'], 'M j') . ' ' . fmt_time($appt['start_at']);
            $heading = 'Your appointment was rescheduled';
            $intro = "<p>Hi $name,</p><p>Your appointment has been moved to <strong>$when</strong>$old. Here are the updated details:</p>";
            $after = $arrive . email_button($manage, 'View or manage appointment');
            break;
        case 'emergency_moved':
            $old = !empty($extra['old_start']) ? e(fmt_date($extra['old_start'], 'M j') . ' at ' . fmt_time($extra['old_start'])) : 'your original time';
            $subject = 'Schedule change: your appointment moved to ' . fmt_date($appt['start_at'], 'M j') . ' ' . fmt_time($appt['start_at']);
            $heading = 'Your schedule was adjusted';
            $accent = '#b45309';
            $intro = "<p>Hi $name,</p><p>The clinic had to attend to an urgent dental case, so your appointment originally set for <strong>$old</strong> was moved to <strong>$when</strong>. We apologize for the inconvenience. If the new time doesn't work for you, you may reschedule or contact the clinic at " . e(CLINIC_PHONE) . '.</p>';
            $after = email_button($manage, 'Reschedule or manage');
            break;
        case 'cancelled':
            $subject = 'Appointment cancelled – ' . $ref;
            $heading = 'Your appointment was cancelled';
            $accent = '#64748b';
            $qr = false;
            $intro = "<p>Hi $name,</p><p>This confirms that your appointment on <strong>$when</strong> has been <strong>cancelled</strong>.</p>";
            $after = email_button(abs_url($appt['patient_id'] ? 'patient/book.php' : 'guest/book.php'), 'Book a new appointment');
            break;
        case 'reminder24':
            $subject = 'Reminder: dental appointment tomorrow at ' . fmt_time($appt['start_at']);
            $heading = 'See you tomorrow!';
            $intro = "<p>Hi $name,</p><p>This is a friendly reminder of your dental appointment on <strong>$when</strong>.</p>";
            $after = $arrive . email_button($manage, 'View or manage appointment');
            break;
        case 'reminder':   // sent by staff from the Email Log, any day before the visit
            $subject = 'Reminder: dental appointment on ' . fmt_date($appt['start_at'], 'M j') . ' at ' . fmt_time($appt['start_at']);
            $heading = 'Appointment reminder';
            $intro = "<p>Hi $name,</p><p>This is a friendly reminder of your dental appointment on <strong>$when</strong>.</p>";
            $after = $arrive . email_button($manage, 'View or manage appointment');
            break;
        case 'reminder12':
        case 'reminder6':
            $hrs = $type === 'reminder12' ? 12 : 6;
            $dayWord = date('Y-m-d', strtotime($appt['start_at'])) === date('Y-m-d') ? 'today' : 'tomorrow';
            $subject = "Reminder: dental appointment $dayWord at " . fmt_time($appt['start_at']);
            $heading = "Your appointment is in about $hrs hours";
            $intro = "<p>Hi $name,</p><p>This is a reminder that your appointment for <strong>" . e($appt['service_name']) . "</strong> is <strong>$dayWord at " . e(fmt_time($appt['start_at'])) . '</strong> (' . e(fmt_date($appt['start_at'], 'l, F j')) . ').</p>';
            $after = '<p style="font-size:14px;color:#3b5566">' . (is_guest_booking($appt) ? 'Please have your QR code ready at the front desk.' : 'Please give your name at the front desk when you arrive.') . '</p>'
                   . $arrive . email_button($manage, 'View or manage appointment');
            break;
        case 'reminder1':   // older 1-hour reminder (no longer scheduled; kept for old records)
            $subject = 'Reminder: your appointment starts in 1 hour';
            $heading = 'Your appointment is in 1 hour';
            $intro = "<p>Hi $name,</p><p>Your appointment for <strong>" . e($appt['service_name']) . '</strong> starts at <strong>' . e(fmt_time($appt['start_at'])) . '</strong> today. See you soon!</p>';
            $after = '<p style="font-size:14px;color:#3b5566">' . (is_guest_booking($appt) ? 'Please have your QR code ready at the front desk.' : 'Please give your name at the front desk when you arrive.') . '</p>';
            break;
        default:
            return false;
    }

    // QR codes are generated only for GUEST bookings (and need the GD extension)
    $qr = $qr && is_guest_booking($appt) && qr_can_png();
    $html = email_layout(e($heading), $intro . $note, appointment_details_table($appt, $qr) . $after, $accent);
    $inline = $qr ? [['cid' => 'qrcode', 'data' => qr_png($appt['code'], 8), 'type' => 'image/png', 'name' => 'qrcode-' . $appt['code'] . '.png']] : [];
    return send_mail($appt['contact_email'], $appt['contact_name'], $subject, $html, $inline);
}
