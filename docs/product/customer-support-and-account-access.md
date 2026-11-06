# Customer support and account access

Configure EASYENGLISH_CONTACT_EMAIL with a valid support email and/or EASYENGLISH_CONTACT_WHATSAPP with the shared EasyEnglish business WhatsApp number in E.164 format. This is a customer-facing destination, not the WhatsApp Cloud API sender ID or a teacher's personal number.

WhatsApp opens a wa.me conversation with a generic prefilled message. The customer must review and send it. Opening support does not send a message, subscribe the customer to service notifications, or reverse an opt-out. Email is shown only for a valid configured address. No placeholder or inferred contact details are displayed.

The account dashboard reuses existing profile, security, appearance, language, and booking verification routes. Phone numbers are masked to their final four digits. Verification states use the authenticated user's existing account fields. Phone verification is linked to the existing booking flow. WhatsApp service-message status comes from the existing whatsapp_contacts consent record; support contact is independent of that consent.

All labels are localized in Romanian and English. No inbox, new messaging pipeline, or new identity mechanism is introduced.
