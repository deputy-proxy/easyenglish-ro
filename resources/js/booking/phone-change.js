export function normalizePhoneDigits(value) {
    return String(value ?? '').replace(/\D+/g, '');
}

export function requiresPhoneReverification(currentPhone, verifiedPhone, { contactsVerified, emailOnly }) {
    return contactsVerified === true
        && emailOnly !== true
        && normalizePhoneDigits(currentPhone) !== normalizePhoneDigits(verifiedPhone);
}
