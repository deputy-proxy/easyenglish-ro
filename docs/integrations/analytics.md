# EasyEnglish analytics setup

## Status

The application includes a consent-gated analytics integration. Production collection remains inactive until a real GTM container ID is configured and a visitor opts into the relevant category.

- GTM container: not created yet.
- GA4 property: not created yet.
- Google Ads: planned, not connected.
- GTM environment variable: `GOOGLE_TAG_MANAGER_ID` (empty by default).
- No GA4 Measurement ID is stored in Laravel. Configure the GA4 Google tag inside GTM.

## 1. Create Google properties

1. Create or select the Google account that will own EasyEnglish measurement.
2. Create a GA4 property for the production EasyEnglish website.
3. Create a Web data stream for the production hostname.
4. Create a Google Tag Manager account and Web container for the same site.
5. In GTM, create a Google tag using the GA4 Measurement ID from the Web data stream.
6. Do not add a second hardcoded Google tag to the Laravel application.

Keep the GA4 Measurement ID and GTM container ID in the organization's Google account, not in source control. Only the GTM container ID is needed by the application.

## 2. Configure the application

Set the real GTM container ID in the production environment:

```dotenv
GOOGLE_TAG_MANAGER_ID=GTM-XXXXXXXX
```

Leave it empty in environments that should not load Google tags. Clear Laravel's cached configuration after changing the environment.

The application injects GTM only after a visitor grants analytics or advertising consent. When neither optional category is granted, GTM is not loaded and no dataLayer events are sent to Google.

## 3. Consent behavior

The cookie preference interface offers:
- Strictly necessary storage: always enabled.
- Analytics: disabled until explicit opt-in.
- Advertising/marketing: disabled until explicit opt-in.

Visitors can accept all optional categories, reject optional categories, or save individual preferences. Before the initial choice, the cookie preferences control stays fixed at the bottom center of the viewport. After a choice is saved, it moves into the normal page flow, centered at the bottom of the page; visitors can reopen the preference panel there at any time. Preferences expire after 180 days. When consent is withdrawn, the application updates Google Consent Mode, clears the corresponding Google analytics/advertising cookies where accessible, and removes disallowed campaign identifiers from first-party storage.

Google Consent Mode v2 is initialized with denied defaults before GTM is inserted. The application then updates:
- `analytics_storage` from analytics consent.
- `ad_storage` and `ad_user_data` from advertising consent. `ad_personalization` remains denied because there is no separate personalized-advertising choice.
- `functionality_storage` and `security_storage` remain granted for essential functionality.

This is a basic consent-mode implementation: GTM is not loaded before an optional category is accepted, so no consent-denied Google pings are sent before opt-in.

The privacy notice is a launch draft and explicitly requires Romanian legal/privacy review. Do not treat this technical interface as legal approval. Confirm controller details, purposes, legal bases, processor terms, retention, and final cookie disclosures before production launch.

## 4. GTM event configuration

Create a Custom Event trigger for each event below. Create Data Layer Variables for the listed parameters. Require analytics_storage as additional consent for the GA4 Google tag itself and every GA4 Event tag; do not rely on denied-consent cookieless pings. For ads_conversion, configure a Google Ads Conversion tag instead, with ad_storage and ad_user_data consent checks. Configure the Google Ads Conversion Linker to run only with advertising consent. Use a single GA4 Google tag as the GA4 destination.

| Internal dataLayer event | Destination / event name | Parameters |
|---|---|---|
| `booking_cta_click` | `booking_cta_click` | `cta_location`, `page_type` |
| `booking_calendar_view` | `booking_calendar_view` | `available_slots_count`, `page_type` |
| `booking_date_select` | `booking_date_select` | `selected_date`, `page_type` |
| `booking_slot_select` | `booking_slot_select` | `selected_date`, `selected_time`, `page_type` |
| `booking_details_start` | `booking_details_start` | `booking_type`, `page_type` |
| `booking_details_submit` | `booking_details_submit` | `booking_type`, `page_type` |
| `booking_error` | `booking_error` | `error_type`, `page_type` |
| `booking_completed` | `generate_lead` | `booking_type`, `lead_type`, `page_type` |
| `section_view` | `section_view` | `section_name`, `page_type` |
| `scroll_depth` | `scroll_depth` | `percent_scrolled`, `page_type` |
| `faq_expand` | `faq_expand` | `faq_question_id`, `page_type` |
| `pricing_cta_click` | `pricing_cta_click` | `package_id`, `package_name`, `page_type` |
| `select_item` | `select_item` | `item_list_name`, `items`, `page_type` |
| `whatsapp_click` | `whatsapp_click` | `page_type` |
| `phone_click` | `phone_click` | `page_type` |
| `email_click` | `email_click` | `page_type` |
| `purchase` | `purchase` | `transaction_id`, `value`, `currency`, `items`, `page_type` |
| `ads_conversion` | Google Ads conversion tag | `conversion_type`, `transaction_id`, `value`, `currency`, `items` |

The application emits `booking_details_submit` after any booking is persisted and confirmed. For introductory bookings it also emits `booking_completed`. GTM should map `booking_completed` to GA4 `generate_lead`, while keeping `booking_details_submit` as a funnel event. If advertising consent is granted, the same confirmed introductory booking also emits a separate `ads_conversion` event with `conversion_type: lead`; this lets a future Google Ads tag operate when analytics consent has not been granted. It does not count a button click, selected slot, invalid form submission, or merely loaded confirmation page as a lead.

The application exposes `purchase` only when the server has reconciled a successful Stripe payment and active entitlement. With analytics consent, it emits the GA4 `purchase` event. With advertising consent, it emits a separate `ads_conversion` event with `conversion_type: purchase`. Each destination has its own browser-side transaction deduplication key. The Stripe Checkout Session ID is used as the transaction ID. UTM source, medium, and campaign values are retained in first-party browser storage for up to 30 days and only after analytics consent. Google Ads click identifiers are left to the Google Ads Conversion Linker in GTM, which must run only after advertising consent.

Do not create GTM tags that send form fields, names, email addresses, phone numbers, notes, session goals, booking references, or other personal data to Google.

## 5. GA4 key events

After publishing the GTM container and confirming the events in DebugView, register useful event parameters as GA4 custom dimensions (for example cta_location, page_type, booking_type, section_name, package_name, and lead_type) and available_slots_count / percent_scrolled as custom metrics:
1. Mark `generate_lead` as a GA4 key event.
2. Mark `purchase` as a GA4 key event.
3. Keep interaction events as ordinary events; do not mark CTA clicks or slot selection as conversions.

## 6. Validation

1. Open the production or staging site in a clean browser profile.
2. Before making a choice, confirm that no request to `googletagmanager.com` or Google Analytics is made.
3. Choose Reject optional. Reload and confirm that no Google tag is loaded.
4. Open preferences, enable Analytics only, and save. Confirm GTM loads and Consent Mode reports analytics granted while all advertising states remain denied.
5. Reopen preferences, enable Advertising, and save. Confirm the advertising states update to granted.
6. Revoke both categories. Confirm consent states update to denied and subsequent events are no longer pushed.
7. Use GTM Preview / Tag Assistant and GA4 DebugView to verify event names and parameters.
8. Complete a test introductory booking and confirm exactly one `generate_lead` event.
9. Complete a Stripe test-mode purchase and confirm one `purchase` event with the expected RON amount and transaction ID.
10. Repeat the tests on mobile and desktop.

## 7. Google Ads (future)

When Google Ads campaigns are ready:
1. Create a Custom Event trigger for `ads_conversion` and configure Google Ads Conversion Tracking tags for `conversion_type=lead` and `conversion_type=purchase`. Require the appropriate advertising consent checks, including ad_storage and ad_user_data.
2. Link the GA4 property to the Google Ads account.
3. Configure Google Ads conversion actions based on the confirmed lead and purchase events.
4. Import GA4 key events or use the dedicated `ads_conversion` events in GTM, but avoid double-counting the same conversion through both paths.
5. Verify Consent Mode v2 behavior and advertising consent with Tag Assistant.
6. Confirm auto-tagging and campaign attribution settings.
7. Keep remarketing and personalized advertising disabled. `ad_personalization` remains denied until a separate consent control and legal review explicitly approve it.

## 8. Operational notes

- Never commit real Google IDs, credentials, or account secrets.
- Keep GTM disabled in local/test environments unless explicitly needed for a controlled test.
- Keep one source of truth for GTM loading; do not install GA4 separately.
- Review event parameters whenever the booking or purchase flow changes.
- The current implementation does not send server-side Measurement Protocol events. Browser purchase tracking is emitted only on the verified purchase-result view.
