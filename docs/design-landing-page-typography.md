# Public landing-page typography

The public landing page uses Instrument Sans from the global --font-sans theme token. Typography roles are implemented in resources/css/app.css under @layer components and applied in resources/views/public/home.blade.php.

| Role | Utility | Desktop/mobile behavior | Intended use |
| --- | --- | --- | --- |
| Section eyebrow | type-eyebrow | 11px, bold, uppercase, restrained tracking | Section overlines |
| Hero supporting line | type-hero-kicker | Responsive 18–24px, semibold | First line of the hero heading |
| Hero headline | type-hero-title | Fluid 40–72px; tighter mobile scale; balanced wrapping | Main page proposition |
| Section heading | type-section-title | Fluid 30–43px; 30–38px on narrow screens | Landing-page section headings |
| Section body | type-section-copy | 16px/1.7, 15px/1.65 on narrow screens | Introductory section descriptions |
| Card heading | type-card-title | 18–20px, semibold | Step and component headings |
| Card body | type-card-copy | 14px/1.65 | Supporting card descriptions |
| Emphasized body | type-body-emphasis | 18px/1.45, semibold | Short editorial statements |
| Metadata | type-meta | 12px/1.5, medium | Counts, captions, and compact supporting details |
| Action label | type-action | 15px/1.25, bold | Primary and secondary action labels |

## Deliberate exceptions

- Hero conversation bubbles and their mobile transcript retain compact, component-specific sizing because their dimensions and timing are part of the conversation composition.
- Testimonial quotations retain their dedicated display treatment in .testimonial-quote.
- Calendar day labels, time slots, availability counts, and timezone notes retain compact sizing for dense interactive data. They must remain legible and must not force calendar columns wider.
- Teacher names and modal biographies retain component-specific sizing to fit portrait cards and the dialog layout.
- Bundle prices retain their numeric display scale; price hierarchy is part of the package comparison, not a general heading role.
- Progress-chart SVG labels retain their chart-specific sizing.

## Responsive and motion rules

- Heading sizes are fluid and use balanced wrapping where appropriate; no typography role animates font size, line height, or letter spacing.
- Section reveal elements continue to use the existing GSAP hooks and data attributes. Typography roles do not alter reveal triggers or element structure.
- Instrument Sans and existing brand color tokens remain unchanged.
- Romanian diacritics use the same font stack and inherit the same line-height and wrapping rules as English copy.
- Reduced-motion behavior remains governed by the existing motion rules; typography adds no motion.
