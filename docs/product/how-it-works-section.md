# How It Works section

## Purpose

The public landing-page process section explains the learner journey in four steps. It is a presentation-only explanation; it does not change booking, WhatsApp call-link delivery, session operations, teacher feedback, or progress-report workflows.

## Approved steps and localization keys

The copy is maintained in `lang/en/public.php` and `lang/ro/public.php`.

| Order | Translation keys | English heading | Romanian heading |
| --- | --- | --- | --- |
| 1 | `step_one_title`, `step_one_text` | Choose your time | Alege-ți intervalul |
| 2 | `step_two_title`, `step_two_text` | Get your call link | Primește linkul pentru apel |
| 3 | `step_three_title`, `step_three_text` | Start speaking | Începe să vorbești |
| 4 | `step_four_title`, `step_four_text` | Track your progress | Urmărește-ți progresul |

The scroll region's localized accessible name uses `how_steps_label`.

Step 4 is intentionally phrased as a capability: after sessions, the teacher can share personalised feedback and recommendations. It must not imply that every free introductory conversation includes a formal assessment or progress report. The free introduction is a separate 15-minute guided conversation, as documented in `public-booking-journey.md`.

## Layout and responsive behaviour

The four cards are rendered in order by the `#how` section in `resources/views/public/home.blade.php`.

- **Below 640px:** one horizontal row. Each card uses `calc(100% - 2.25rem)` of the track width, leaving a visible preview of the next card. The user can swipe horizontally.
- **640px to 1023px:** the same horizontal scroll track remains active. Cards use a capped two-column-sized basis (`min(340px, calc((100% - 16px) / 2))`) so the sequence remains one row and can be swiped.
- **1024px and above:** the track becomes a four-column CSS grid. All four cards are visible simultaneously on one horizontal line; there is no desktop carousel or horizontal scrolling.

The scroll track uses native horizontal overflow, `scroll-snap-type: x mandatory`, contained overscroll, and hidden scrollbars. It does not autoplay and introduces no carousel dependency. The partial next-card preview is the primary mobile swipe affordance.

## Images

The existing image assets are reused unchanged and mapped by step order:

1. `public/images/step-1.png`
2. `public/images/step-2.png`
3. `public/images/step-3.png`
4. `public/images/step-4.png`

Only their rendered container height and layout may vary by breakpoint. Image replacement is a separate follow-up task.

## Accessibility and motion

- The scrollable track is a labelled `role="region"` and is keyboard-focusable with `tabindex="0"` so keyboard users can scroll it.
- Each card retains semantic article and heading structure; the step number remains visible in the card.
- The native scroll container allows vertical page scrolling as well as horizontal interaction (`touch-action: pan-x pan-y`).
- Scroll snapping helps cards settle at predictable positions. The first and last cards remain reachable.
- The existing GSAP `data-reveal-stagger`, `data-reveal-item`, and `data-image-reveal` hooks are preserved. No separate animation initialization is added.
- The existing animation bootstrap skips non-essential animations when `prefers-reduced-motion` is enabled. Native scrolling remains available; the CSS explicitly avoids smooth-scroll motion in that mode.
- Content remains in the HTML and the horizontal track remains scrollable without JavaScript, so the section does not depend on a client-side carousel runtime.

## Implementation locations

- `resources/views/public/home.blade.php` — four-card markup, accessible scroll region, and reuse of existing step images.
- `resources/css/app.css` — one-row horizontal scroll/snap treatment and desktop four-column grid breakpoint.
- `lang/en/public.php` and `lang/ro/public.php` — approved step copy and accessible region label.
- `tests/Feature/ExampleTest.php` — localized copy, four-card count/order, image order, accessible region hooks, and responsive CSS contract.

## Scope boundaries

This section describes the product journey only. It does not create bookings, generate or send WhatsApp links, initiate calls, record teacher feedback, calculate progress, or create progress reports. Those remain owned by the existing booking, call-event, and learner-progress workflows.
