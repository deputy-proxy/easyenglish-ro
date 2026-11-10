# Superseded ADR: Operator-controlled WhatsApp provider selection

- Status: Superseded on 2026-11-10
- Original date: 2026-11-07
- Superseded by: [Purpose-based WhatsApp provider routing](whatsapp-purpose-based-routing.md)

This document is retained as a historical record of the former global-provider-selection design. Its WHATSAPP_PROVIDER selector, manual Meta/OpenWA notification failover, and shared gateway binding are no longer current architecture. Do not follow its deployment or failover instructions.

The current policy routes Meta exclusively to contact-verification OTPs and OpenWA exclusively to ordinary customer/teacher notifications. Email is independent, and scheduled OpenWA call events remain under their separate OPENWA_ENABLED flag. See the new ADR and [the current operations runbook](../operations/whatsapp-provider-failover.md).
