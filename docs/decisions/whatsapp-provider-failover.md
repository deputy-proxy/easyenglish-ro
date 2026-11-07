# ADR: Operator-controlled WhatsApp provider selection

- Status: Accepted for implementation
- Date: 2026-11-07

## Context

Easy English currently uses Meta WhatsApp Cloud API for transactional messages and an isolated OpenWA service for scheduled call events. OpenWA is unofficial WhatsApp Web automation and can be disconnected or restricted. The business requires a rapid path to continue transactional communication through Meta without a code deployment.

## Decision

- Keep one application-facing `WhatsAppGateway` contract using provider-neutral template/text message DTOs.
- Implement Meta and OpenWA adapters behind a single container binding.
- Select the outbound adapter with `WHATSAPP_PROVIDER=meta|openwa`; default to `meta` to preserve existing behavior.
- Configure both provider credentials/capabilities independently. The inactive provider's outage must not make the active provider unhealthy.
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
- OpenWA remains an unofficial provider with account-restriction risk. The fallback does not remove that risk.
- Meta and OpenWA sender-number coexistence must be verified against the actual account arrangement. A fallback may need a separate number, which can change the sender shown to customers.
- Delivery is not exactly-once across external providers. The system prevents blind cross-provider replay and uses stable logical idempotency keys wherever provider state can be reconciled.
