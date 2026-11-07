# ADR: Operator-controlled WhatsApp provider selection

- Status: Accepted; OpenWA selected as primary production provider
- Date: 2026-11-07

## Context

Easy English supports Meta WhatsApp Cloud API and an isolated OpenWA service behind one provider-neutral gateway. OpenWA is unofficial WhatsApp Web automation and can be disconnected, restricted, or affected by upstream web-client changes. After a successful live verification test using a dedicated paired account, the product owner selected OpenWA as the primary outbound provider. Meta remains a manually selected fallback.

## Decision

- Keep one application-facing `WhatsAppGateway` contract using provider-neutral template/text message DTOs.
- Implement Meta and OpenWA adapters behind a single container binding.
- Select the outbound adapter with `WHATSAPP_PROVIDER=meta|openwa`. The code-level default remains `meta` for safe compatibility, while production explicitly sets `WHATSAPP_PROVIDER=openwa`.
- Configure both provider credentials/capabilities independently. The inactive provider's outage must not make the active provider unhealthy. Keep Meta configured as a manual fallback, but do not automatically fail over.
- Keep `WHATSAPP_ENABLED` for Meta and `OPENWA_MESSAGING_ENABLED` for OpenWA messaging. Preserve `OPENWA_ENABLED` as the existing scheduled-call-event flag.
- Keep the Laravel notification outbox as the source of truth. Record the active provider and append per-attempt provider history.
- Resolve the selected provider when a queued delivery is handled, not when the outbox row is created.
- Do not automatically fail over individual sends. The operator changes the global selector after checking in-flight/ambiguous deliveries.
- Treat connection loss or provider acceptance followed by local persistence failure as ambiguous. Quarantine the delivery until provider reconciliation determines whether it is safe to retry.
- Keep Meta and OpenWA inbound callbacks authenticated and normalize inbound events into the existing shared message/opt-out handler.

## Consequences

- Switching requires updating the environment variable, rebuilding Laravel config cache, and restarting queue workers. It does not require a code deployment or schema migration after this feature is deployed.
- A migration adds provider and provider-attempt history to existing notification deliveries; this is a one-time feature rollout migration, not part of an emergency switch.
- Meta templates and the OpenWA text-rendering catalogue must remain aligned with application notification events.
- OpenWA is the selected primary despite its unofficial status; the business explicitly accepts the increased account-restriction and upstream-compatibility risk. Meta remains a manual fallback, subject to account/number coexistence constraints.
- Meta and OpenWA sender-number coexistence must be verified against the actual account arrangement. A fallback may need a separate number, which can change the sender shown to customers.
- Delivery is not exactly-once across external providers. The system prevents blind cross-provider replay and uses stable logical idempotency keys wherever provider state can be reconciled.
## Production selection (7 November 2026)

The production environment explicitly selects `WHATSAPP_PROVIDER=openwa`. Keep `OPENWA_MESSAGING_ENABLED=true` on Hostinger and Railway, `OPENWA_ALLOW_LIVE_TEST=true` on Railway for the dedicated paired account, and preserve the existing one-replica/persistent-volume/session-encryption-key requirements. The dedicated account completed pairing and a live EasyEnglish verification message succeeded. This records the selected operating policy; it does not guarantee future availability or remove the need for ongoing delivery and session monitoring. The emergency fallback remains operator-controlled: set `WHATSAPP_PROVIDER=meta`, refresh Laravel config cache, restart queue workers, verify provider readiness, and reconcile ambiguous deliveries before any retry.
