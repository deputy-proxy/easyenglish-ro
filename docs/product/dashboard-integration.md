# Customer and teacher dashboard integration

## Delivery status

The customer identity foundation and all customer/teacher dashboard implementation workstreams in issues #221–#228 are merged and their GitHub issues are closed.

| Workstream | Issue | Specification |
| --- | --- | --- |
| Durable customer ownership | #221 | [Customer identity and record ownership](../architecture/customer-identity-and-record-ownership.md) |
| Customer sessions and history | #222 | [Customer sessions](customer-sessions.md) |
| Purchases, payments, and credits | #223 | [Customer purchases and payments](customer-purchases-and-payments.md) |
| Learner profile and goals | #224 | [Learner profile](learner-profile.md) |
| Assessments and progress reports | #225 | [Learner progress and assessments](learner-progress-and-assessments.md) |
| Customer navigation and overview | #226 | [Customer dashboard overview](customer-dashboard-overview.md) |
| Support and account access | #227 | [Customer support and account access](customer-support-and-account-access.md) |
| Teacher workspace | #228 | [Teacher dashboard](teacher-dashboard.md) |

This document is the integration map. Each linked specification remains the source of detailed behavior for its domain.

## Information architecture

### Customer

The authenticated customer dashboard has five primary areas:

1. **Overview** — next session, usable credits, level source, persisted progress highlight, recent activity, and booking quick actions.
2. **My sessions** — upcoming and historical bookings, current call-link state, and eligible signed management actions.
3. **My learning** — profile/goals, assessed versus self-reported level, assessment history, published reports, and persisted teacher feedback/recommendations.
4. **My purchases** — entitlement snapshots, payment/refund/dispute state, and credit-ledger activity.
5. **My account** — existing profile, contact verification, security, appearance, language, and configured support channels.

Booking remains a separate prominent action that opens the existing calendar. Account, security, payment, verification, and messaging flows are reused rather than reimplemented.

### Teacher

Active teachers enter /teacher/dashboard, not the customer dashboard. The teacher workspace includes today's and upcoming assigned sessions, the next session, weekly scheduled workload, feedback tasks, availability exceptions, Google Calendar freshness, and a paginated/filterable assigned-session list. Availability and calendar mutations remain on the existing teacher availability routes. A staff member who also teaches uses the teacher workspace for teaching and the separately authorized Filament /admin panel for staff operations.

### Staff

Filament remains the operational administration interface. A teacher assignment is not a staff permission, and staff status does not broaden the teacher's access to customer records. Dual-role users see the teacher workspace for assigned teaching work and can open staff administration only when independently authorized.

## Shared data contracts and source of truth

- **Customer ownership:** customer_user_id is authoritative for owned bookings and entitlements. The narrow legacy fallback is documented in the ownership architecture and requires exact verified email and phone matches. Teacher assignment through teacher_id is a separate relationship.
- **Session state:** booking records and existing booking services remain authoritative for status, signed management actions, cancellation/rescheduling, and credit restoration/forfeiture. Customer and teacher views do not create parallel booking state.
- **WhatsApp call links:** only the current OpenWA call event matching booking revision, assigned teacher, and scheduled boundaries can expose a join URL. Customer and teacher authorization checks remain separate. A link is never inferred from a pending/failed event.
- **Purchases and payments:** persisted entitlement snapshots, verified Stripe payment/refund events, and the credit ledger are authoritative. A receipt is not represented as available until a real provider/accounting artifact exists.
- **Learner level:** the latest published Progress Report with a recorded overall CEFR level takes precedence over the learner's self-reported account level. Numeric feedback scores are not converted into CEFR.
- **Reports:** shared_at is the publication boundary. Unshared drafts remain staff-only; customer report reads are scoped through owned bookings.
- **Feedback:** assigned teachers can record feedback only after a confirmed session ends. Authorized staff retain the existing Filament management workflows.
- **Support and notifications:** customer support uses only explicitly configured official contact destinations. Support contact does not subscribe a user to WhatsApp service messages or undo an opt-out.

## Privacy and role boundaries

| Data | Customer | Assigned teacher | Authorized staff |
| --- | --- | --- | --- |
| Own bookings/session details | Own records | Assigned bookings only | Operational access |
| Learner contact email/phone | Own account | Not exposed in teacher workspace | As required for operations |
| Session goal and recorded English level | Own records | Assigned booking only | Operational access |
| Private booking notes | Own booking context per customer view | Not exposed | Operational access |
| Payment, entitlement, refund, and credit data | Own records | Not exposed | Operational access |
| Published progress reports | Own reports | Not implicitly granted by assignment | Operational access |
| Draft progress reports | Not visible | Not visible | Authorized authors/editors |
| OpenWA join URL | Current authorized owned booking | Current authorized assigned booking | Authorized operational access |
| Google OAuth tokens and provider secrets | Not visible | Not visible | Never displayed in UI |

The server enforces these boundaries. Hiding a navigation item is not an authorization mechanism.

## Query and performance review

- Customer session, purchase, assessment, and report histories use bounded pagination. The overview uses capped upcoming-session, bundle-summary, and recent-activity queries with aggregate counts for totals.
- Customer dashboard queries eager-load the teacher/call-event, entitlement/payment/refund/dispute, feedback, booking, and report relationships needed by their respective screens. Published-report and assessment reads are scoped through owned booking IDs.
- Teacher overview reads are bounded to six today/upcoming sessions and five feedback tasks. The session list paginates at 15 records and eager-loads only the current OpenWA event fields needed for status rendering. Workload totals use database aggregates. Calendar and exception summaries are capped.
- Teacher session detail selects only the booking fields needed for the appointment and feedback workflow; it does not load entitlement, payment, customer contact, or private notes.
- All date boundaries and displayed session times use Europe/Bucharest. No dashboard endpoint loads unbounded session, report, payment, or credit history.

## Localization, accessibility, and responsive behavior

Customer and teacher dashboard experiences support Romanian and English. Teacher availability and Google Calendar management use the same session locale. The Blade/Tailwind implementation remains the existing frontend architecture; no new framework or duplicate domain service is introduced. Navigation exposes current-page state, forms use labels, feedback/errors use status/alert semantics, and lists/history have bounded pagination. Customer and teacher views retain light/dark theme utility support and responsive layouts for narrow and wide screens.

## Automated validation evidence

The merged workstreams include role/ownership/privacy tests, session lifecycle and history tests, purchase/payment/credit reconciliation tests, profile and assessment/report visibility tests, account/support consent tests, and teacher assignment/calendar/feedback tests.

The full PHP CI suite, Pint, PHPStan, frontend interaction tests, and frontend production build are required on the integration PR. The CI workflow also runs the isolated OpenWA PoC tests, dependency audit, entrypoint validation, and production image build.

## Release acceptance and outstanding gates

The implementation and automated integration checks do not themselves prove production readiness. Before production rollout:

1. Deploy to the intended staging environment with production-like role/configuration data and no live customer data unless appropriately protected.
2. Verify customer and teacher flows in Romanian and English at mobile and desktop breakpoints, in light and dark appearance.
3. Verify customer/teacher/staff role boundaries with separate test accounts, including a dual-role staff/teacher.
4. Verify real Google OAuth/calendar sync and OpenWA call-link lifecycle only with approved staging credentials and test devices.
5. Confirm Stripe, email, Meta WhatsApp templates/consent, legal/privacy, invoicing, backup, queue, and monitoring gates in the launch checklist.
6. Record staging acceptance before promoting the dashboard release to production.

Staging and real-device acceptance are operational release gates, not claims made by the automated test suite. See the [launch checklist](../operations/launch-checklist.md), [staff runbook](../operations/staff-runbook.md), and [Hostinger deployment runbook](../deployment/hostinger.md).
