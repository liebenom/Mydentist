<?php
/** Resolve the picker fields into contact data. Returns [data, error]. */
function resolve_patient_fields(): array
{
    if (input('ptype') === 'registered') {
        $p = db_one("SELECT * FROM users WHERE id = ? AND role = 'patient'", [input_int('patient_id')]);
        if (!$p) return [null, 'Please select a registered patient.'];
        return [['patient_id' => (int) $p['id'], 'contact_name' => $p['full_name'], 'contact_email' => $p['email'], 'contact_phone' => $p['phone']], null];
    }
    $name = input('contact_name');
    $email = strtolower(input('contact_email'));
    $phone = input('contact_phone');
    if (mb_strlen($name) < 2) return [null, "Please enter the patient's name."];
    if ($email !== '' && !valid_email($email)) return [null, 'The email address is not valid.'];
    if ($phone === '' && $email === '') return [null, 'Please enter a contact number or email.'];
    // Walk-in details are not attached to any account by email (privacy); pick
    // "Registered patient" to book for an account holder.
    return [['patient_id' => null, 'contact_name' => $name, 'contact_email' => $email, 'contact_phone' => $phone], null];
}
