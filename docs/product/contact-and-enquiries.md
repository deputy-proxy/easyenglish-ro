# Contact page and enquiries

The public contact page is available at `/contact`. Visitors can submit their name, email address, subject, and message without creating an account. Valid submissions are stored in the `enquiries` table through the `App\\Models\\Enquiry` model.

Submissions start with the `new` status. Authorized staff can review the full message and update its status to `in_progress` or `resolved` in **Admin → Website → Enquiries**. The resource is available to staff accounts only; creation and deletion are disabled in Filament.

The POST endpoint is protected by Laravel CSRF middleware, a per-IP request throttle, server-side field validation, and a honeypot field. Contact data is limited to the information required to respond. No outbound email notification is sent by this feature; the database record is the durable submission.
