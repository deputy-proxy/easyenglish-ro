# Privacy notice — launch draft requiring review

**Status: NOT APPROVED FOR PUBLICATION.** This is an implementation draft, not legal advice. The controller's legal identity, registered/contact address, final legal bases, processor terms, transfer safeguards, and complaint contact must be completed and reviewed by a qualified Romanian privacy/legal professional before this notice is published or production booking is enabled.

## 1. Controller and contact

- Controller legal name: **[complete with registered legal name]**
- Registered address: **[complete]**
- Privacy contact email: **[complete and verify]**
- Data protection officer: **[state whether appointed and provide contact, if applicable]**

Do not launch while required controller details are blank. Do not invent a DPO or claim that one is appointed without confirmation.

## 2. Information processed

Depending on the interaction, Easy English processes the learner's name, email address, phone/WhatsApp number, English level, session goal, optional notes, selected appointment and its status, assigned teacher contact details after confirmation, policy-version acceptance, contact-verification state, bundle and credit records, payment/refund references, service-message consent and opt-out state, and limited delivery/audit/technical records. When a teacher connects Google Calendar, the application reads event Busy/Free status and times to calculate availability. It stores normalized Busy interval boundaries and provider event IDs, not event titles, descriptions, attendees, or other private calendar content. For a confirmed session, the assigned teacher's calendar receives an event containing the learner's name, email, phone, English level, session goal, optional notes, appointment time, and booking reference.

Do not submit sensitive personal information in session goals or notes. Session calls are handled in WhatsApp; the service does not intentionally record calls or store WhatsApp conversation contents as part of this booking application.

## 3. Purposes and legal bases

The service uses data to respond to booking requests, verify contact details, manage appointments and prepaid entitlements, process payments and refunds, send requested service communications, prevent abuse, maintain security/audit records, and meet applicable accounting and legal obligations. The final controller must map each purpose to the correct GDPR legal basis (including steps requested before a contract, contract performance, legal obligation, legitimate interests where appropriate, and consent for the relevant communication channel) and document that assessment before publication. Do not treat service-message consent as marketing consent.

## 4. Recipients and processors

Depending on the configured service, data may be processed by hosting/database providers, Stripe for payment processing, email delivery providers, Meta/WhatsApp for service messages, Google Calendar for teacher availability and confirmed-session events, and authorized staff who operate bookings and payments. Confirm the actual providers, contracts, subprocessors, and applicable international-transfer mechanisms before launch. Payment card details are handled by Stripe Checkout; the application should not collect or log full card details.

## 5. Retention

Session goals and optional notes are proposed to be deleted or anonymized 12 months after the scheduled session, subject to approval of the legal basis and retention schedule. Booking references, contact details, bundle/credit ledger entries, payment/refund references, invoice/receipt references, and audit records may need different retention periods. Financial and legally required records must not be erased merely because a session's notes reach their retention limit. The detailed category-by-category proposal is in [the retention policy](retention-policy.md).

The automated retention operation must remain disabled until the retention schedule and legal bases have been approved and the production configuration has been explicitly enabled.

## 6. Data subject rights

Subject to the applicable conditions and exceptions, individuals may request access, rectification, erasure, restriction, portability, or object to processing, and may withdraw consent where processing relies on consent. Requests must be routed to **[verified privacy contact]**, identity checked proportionately, tracked, and answered within the applicable statutory period. Erasure is not absolute: statutory accounting obligations, legal claims, fraud prevention, and other lawful exceptions may require limited records to be retained. Establish a documented request-handling procedure before launch.

Individuals may lodge a complaint with the Romanian data protection supervisory authority (ANSPDCP). Confirm the final contact details and link in the approved publication version.

## 7. Security and international transfers

Access is restricted to authorized staff; payment webhooks are verified; application logs should omit secrets and unnecessary learner data. Confirm processor agreements, data locations, transfer mechanisms where applicable (including Google and Meta), incident-response responsibilities, and backup retention before launch. Teacher OAuth access/refresh tokens are encrypted at rest; teachers authorize their own calendar and can disconnect after future sessions and outstanding event removals are resolved. No session recording or storage of WhatsApp conversation contents is intended by default.

## 8. Changes and approval

Record the version and effective date of the approved notice. The production notice must match the actual controller, processors, legal bases, contact routes, and operational behavior. This draft must not be presented as approved until sign-off is recorded in the launch checklist.
