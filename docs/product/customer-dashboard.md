# Customer dashboard

This is the entry point for the authenticated customer experience documentation. The dashboard is organized around the learner's next action and persisted account history, while reusing the existing booking, account, payment, verification, and progress services.

## Primary areas

1. **Overview** — next session, remaining usable credits, current level and its source, recent persisted activity, progress highlight, and booking actions.
2. **My sessions** — upcoming and historical bookings, appointment details, call-link status, and eligible signed cancellation/rescheduling actions. See [Customer sessions](customer-sessions.md).
3. **My learning** — learner profile and goals, assessed versus self-reported level, assessment history, published reports, and evidence-based recommendations. See [Learner profile](learner-profile.md) and [Learner progress and assessments](learner-progress-and-assessments.md).
4. **My purchases** — purchased bundles, entitlement snapshots, payment/refund/dispute state, credit ledger, and receipt availability. See [Customer purchases and payments](customer-purchases-and-payments.md).
5. **My account** — profile/contact verification, security, appearance, language, WhatsApp notification consent state, and official configured support channels. See [Customer support and account access](customer-support-and-account-access.md).

Booking remains a separate prominent action that opens the existing availability calendar. It is not duplicated as a sixth dashboard section.

The Profile, Security, and Appearance settings routes use the same branded dashboard header and visual system as the customer workspace. Their settings navigation remains within the shared shell, with a direct return to My account. Existing Fortify, verification, password-confirmation, passkey, two-factor, and appearance-preference behavior is preserved.

## Detailed specifications

- [Customer dashboard overview and navigation](customer-dashboard-overview.md)
- [Customer sessions and history](customer-sessions.md)
- [Purchases, payments, credits, and receipt availability](customer-purchases-and-payments.md)
- [Learner profile and goals](learner-profile.md)
- [Learner progress, assessments, and reports](learner-progress-and-assessments.md)
- [Account access and support channels](customer-support-and-account-access.md)
- [Customer identity and record ownership](../architecture/customer-identity-and-record-ownership.md)

For the combined customer/teacher architecture, shared data contracts, privacy boundaries, performance review, and release gates, see the [dashboard integration overview](dashboard-integration.md).
