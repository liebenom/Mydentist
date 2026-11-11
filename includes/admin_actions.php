<?php
/**
 * Staff actions on an appointment, shared by every admin page.
 * POST fields: appt_action, appt_id, note (optional), back (relative URL to return to)
 */

const ADMIN_ACTIONS = [
    'approve'  => ['from' => ['pending'], 'to' => 'confirmed', 'mail' => 'confirmed', 'msg' => 'Appointment approved and patient notified.'],
    'decline'  => ['from' => ['pending'], 'to' => 'cancelled', 'mail' => 'declined', 'msg' => 'Request declined and patient notified.'],
    'cancel'   => ['from' => ['pending', 'confirmed'], 'to' => 'cancelled', 'mail' => 'cancelled', 'msg' => 'Appointment cancelled and patient notified.'],
    'complete' => ['from' => ['confirmed', 'pending'], 'to' => 'completed', 'mail' => null, 'msg' => 'Appointment marked as completed.'],
    'no_show'  => ['from' => ['confirmed', 'pending'], 'to' => 'no_show', 'mail' => null, 'msg' => 'Appointment marked as no-show.'],
    'checkin'  => ['from' => ['confirmed', 'pending'], 'to' => null, 'mail' => null, 'msg' => 'Patient checked in.'],
    'reopen'   => ['from' => ['completed', 'no_show', 'cancelled'], 'to' => 'confirmed', 'mail' => null, 'msg' => 'Appointment status reset to Confirmed.'],
];

function admin_apply_action(int $id, string $action, string $note = ''): array
{
    $appt = find_appointment($id);
    $def = ADMIN_ACTIONS[$action] ?? null;
    if (!$appt || !$def) {
        return ['ok' => false, 'msg' => 'Appointment or action not found.'];
    }
    if (!in_array($appt['status'], $def['from'], true)) {
        return ['ok' => false, 'msg' => 'This action is not allowed for a ' . status_label($appt['status']) . ' appointment.'];
    }
    if ($action === 'checkin' && !is_front_desk()) {
        return ['ok' => false, 'msg' => 'Check-in is done by staff at the front desk.'];
    }
    if ($action === 'reopen' && strtotime($appt['start_at']) > time() && $appt['status'] === 'cancelled') {
        // re-activating a future slot: make sure it is still free
        $busy = busy_map(date('Y-m-d', strtotime($appt['start_at'])), $id);
        if (overlaps_any($busy[(int) $appt['dentist_id']] ?? [], strtotime($appt['start_at']), strtotime($appt['end_at']))) {
            return ['ok' => false, 'msg' => 'That time slot has been taken by another booking. Reschedule instead.'];
        }
    }
    if ($action === 'checkin') {
        db_update('appointments', ['checked_in_at' => now_str(), 'status' => 'confirmed', 'updated_at' => now_str()], 'id = :id', ['id' => $id]);
    } else {
        set_status($id, $def['to'], $note !== '' ? ucfirst(str_replace('_', '-', $action)) . ': ' . $note : null);
    }
    audit('appointment_' . $action, $appt['code'] . ' (' . $appt['contact_name'] . ', ' . fmt_datetime($appt['start_at']) . ')' . ($note ? " – $note" : ''), $id);
    if ($def['mail']) {
        notify($id, $def['mail'], ['note' => $note]);
    }
    return ['ok' => true, 'msg' => $def['msg']];
}

/** Process a posted action (if any) and redirect back. Call at the top of admin pages. */
function handle_admin_action_post(): void
{
    if (!is_post() || input('appt_action') === '') {
        return;
    }
    csrf_check();
    $res = admin_apply_action(input_int('appt_id'), input('appt_action'), mb_substr(input('note'), 0, 300));
    flash($res['ok'] ? 'success' : 'danger', e($res['msg']));
    $back = input('back');
    if ($back && str_starts_with($back, 'admin/') && !str_contains($back, '//')) {
        redirect($back);
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

/** Small inline form button for an action. */
function action_button(array $appt, string $action, string $label, string $class, string $icon, string $back = '', string $confirm = ''): string
{
    $def = ADMIN_ACTIONS[$action];
    if (!in_array($appt['status'], $def['from'], true) || ($action === 'checkin' && !is_front_desk())) {
        return '';
    }
    return '<form method="post" class="d-inline"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="appt_action" value="' . $action . '"><input type="hidden" name="appt_id" value="' . (int) $appt['id'] . '">'
        . ($back ? '<input type="hidden" name="back" value="' . e($back) . '">' : '')
        . '<button class="btn btn-sm ' . $class . '" title="' . e($label ?: ucfirst(str_replace('_', '-', $action))) . '"><i class="bi bi-' . $icon . '"></i> ' . e($label) . '</button></form>';
}
