# ADR: Purpose-based WhatsApp provider routing

- Status: Accepted
- Date: 2026-11-10
- Supersedes: [Operator-controlled WhatsApp provider selection](whatsapp-provider-failover.md)

## Context

The previous architecture selected one global WhatsApp gateway for both contact verification and transactional notifications. This coupled unrelated capabilities and encouraged manual failover between Meta Cloud API and OpenWA. OpenWA first-time-recipient/licensing behavior also needs verification against the actual deployed service; an assumed licence requirement must not dictate the code architecture.

## Decision

- Route phone/contact verification OTPs exclusively through Meta Cloud API.
- Route ordinary customer and teacher transactional notifications exclusively through OpenWA.
- Keep email independent of both WhatsApp routes.
- Keep scheduled OpenWA call-event operations separate under OPENWA_ENABLED.
- Express the routing boundary through WhatsAppVerificationGateway and WhatsAppNotificationGateway.
- Keep Laravel as the source of truth for verification challenges, notification outbox records, consent/opt-out, provider status, retry state, and audit history.
- Persist provider attribution and attempt record before sending a notification.
- Process Meta status callbacks only against Meta-origin notification records or a verification challenge correlated by opaque callback key/message ID.
- Normalize inbound STOP messages from both providers through the same consent/opt-out service. Suppress pending/queued customer and teacher WhatsApp deliveries; never suppress email.
- Do not automatically fail over between providers. Missing provider, failed send, or ambiguous outcome remains visible and follows existing safe failure/reconciliation rules.
- Preserve verification challenge expiry, code attempt limits, resend cooldown, and email verification behavior.
- Preserve the existing OpenWA call-event integration and its separate feature flag.

## Configuration

- META_WHATSAPP_VERIFICATION_ENABLED controls Meta OTP sending. WHATSAPP_ENABLED is a temporary backward-compatible fallback only when the new flag is absent.
- OPENWA_MESSAGING_ENABLED controls OpenWA transactional notifications on Laravel and Railway.
- OPENWA_ENABLED continues to control scheduled call-event operations.
- WHATSAPP_PROVIDER is obsolete and no longer controls routing.
- No production environment variables are changed automatically by this code change.

## Consequences

- Provider selection is determined by purpose in code, not mutable global configuration.
- Meta transactional templates other than the authentication template are not needed for ordinary notifications; OpenWA renders the notification catalogue.
- OpenWA outages do not send OTPs through an alternate provider; Meta outages do not send ordinary notifications through Meta.
- Status and monitoring report Meta verification, OpenWA messaging, and OpenWA call events independently.
- OpenWA first-time-recipient/licence behavior must be validated with a number never previously contacted by the paired account before general traffic is enabled. If the service requires a licence, configure the optional OPENWA_LICENSE_KEY only in Railway secrets; never in Laravel.
- Existing accepted/ambiguous delivery state is never blindly replayed across providers.
