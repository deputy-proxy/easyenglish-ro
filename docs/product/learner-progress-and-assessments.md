# Learner progress and assessments

## Purpose and customer entry point

The authenticated dashboard's **Progress** view is the learner's longitudinal learning record. It combines:

- the existing five-dimension radar chart;
- complete and partial session-assessment history;
- a paginated archive of published progress reports;
- report detail views; and
- next steps taken only from persisted teacher/staff-authored report recommendations or feedback.

The view is customer-scoped. It does not create automated scores, infer CEFR levels, or generate learning advice.

## Source records and lifecycle

### Session feedback

The session_feedback table stores one row per booking and metric. Customer-visible metric keys are vocabulary, pronunciation, fluency, grammar, and confidence. The overall key stores the teacher's overall written feedback and is not a sixth scored dimension.

The assigned teacher's post-session workflow is /teacher/bookings/{reference}. It is available only to the currently assigned teacher and only after a confirmed session has ended. The teacher can save one or more current scores (0–100) and/or overall written feedback. A text-only submission is valid and remains visible as an incomplete assessment.

Authorized staff can also manage SessionFeedback in Filament. That resource supports baseline, current, and potential scores plus qualitative feedback for each metric. recorded_by identifies the account that last recorded the row.

### Assessment completeness

A session assessment is **complete** only when all five customer-visible dimensions have a non-null current_score. Zero is a valid recorded score.

A session assessment is **partial** when it has at least one current score, a potential score, or non-empty teacher feedback, but fewer than five current dimension scores. This includes text-only feedback and potential-only entries. A baseline-only row is an initial reference, not a session assessment, and is not counted as partial by itself.

Partial assessments appear in the chronological history with their scored-dimension count. Missing scores stay blank. They are never filled with averages, estimates, or values from another session.

## Score meanings

| Value | Meaning | Customer presentation |
| --- | --- | --- |
| Baseline | Earliest chronological non-null baseline score recorded for a dimension | Initial reference, shown separately |
| Current | Teacher's observed score for that dimension for the associated session | Measured current score |
| Potential | Separate estimate of possible future development | Potential only; never treated as achieved progress |
| Improvement | Difference between a complete assessment's current score and the initial baseline for the same dimension | Positive point difference only; not a percentage or aggregate CEFR result |

Baseline values are selected independently per dimension from the earliest chronological booking with a non-null baseline. The chart displays a baseline series only when all five baseline dimensions exist.

## Radar chart and comparison semantics

The existing five-dimension radar chart is retained.

- It displays the baseline (when complete) and up to the three most recent complete session assessments.
- Complete-assessment and partial-assessment totals are calculated across the learner's full history.
- The strongest measured improvement compares the latest complete assessment with the complete baseline, dimension by dimension. It is available only when all five baseline values exist.
- Partial assessments never become radar polygons and never influence the strongest-improvement calculation.
- Potential scores are not charted as achieved progress.
- Scores that are equal or lower remain as recorded. The chart does not manufacture growth.
- The chart's SVG has a descriptive accessible label; series selection uses keyboard-operable buttons with pressed state and a live details region. A textual data table and the full assessment-history table provide non-visual alternatives.
- Trend copy describes observations only. It does not claim statistical significance, standardized test performance, or guaranteed learning outcomes.

The chart query is bounded: it fetches at most five earliest baseline rows and the dimension rows for the three latest complete assessments. SQL aggregates calculate complete/partial counts across the full customer-owned history. Latest feedback cards are bounded to one latest row per customer-visible metric plus overall feedback.

## Assessment history

The assessment history is ordered by session date, newest first, and paginated at ten sessions per page. It includes complete and partial records that contain a current score, potential score, or non-empty feedback.

Each entry includes:

- session date and duration;
- complete/incomplete status and count of the five scored dimensions;
- baseline reference, current score, and potential score for each dimension;
- available dimension-specific feedback and overall teacher feedback; and
- a signed link to the related booking/session detail.

The baseline is a reference value, not a score newly achieved in each session. Potential remains explicitly separate from current performance.

## Progress reports

ProgressReport.shared_at is the publication boundary:

- shared_at IS NULL: draft, private to authorized staff; never returned to the customer.
- shared_at IS NOT NULL: published report eligible for the owning customer.

The customer archive is ordered by shared_at DESC, id DESC and paginated at ten reports per page. Report detail includes the title, overall level when recorded, summary, strengths, areas to improve, recommendations, publication date, author when available, and a signed link to the related session.

A report detail request is scoped by both its ID and the authenticated customer's owned booking IDs. An unshared report or another customer's report returns 404, including when its ID is supplied directly in the query string.

overall_level is a report-authored CEFR label. The learner profile uses the latest published report with a non-null overall level as the assessed source; otherwise it displays the account's self-reported level. Numeric session-feedback scores are never converted to CEFR.

## Next steps and recommendation provenance

The dashboard uses this order:

1. If the latest published report has recommendations, display that exact persisted text and link to the source report.
2. Otherwise, display the latest non-empty persisted feedback for each dimension and/or overall feedback, with its metric and session date.
3. If neither exists, show a neutral empty state.

No recommendations are synthesized from scores, attendance, incomplete assessments, or general assumptions. The report's author and publication date remain visible in the archive/detail.

## Authorization and privacy

- All session-feedback and report queries are scoped through Booking::ownedBy(authenticated customer).
- Explicit customer_user_id ownership is authoritative. The legacy fallback is restricted to unowned records with verified email and phone matches, as documented in the customer identity and record ownership architecture.
- Draft/unshared reports are never exposed.
- A customer cannot open another customer's report by guessing or changing a report ID.
- Assessment history only eager-loads the authenticated customer's paginated bookings and their feedback rows.
- Report archives/details eager-load the associated booking and author.
- Session links are signed and generated from the owned booking associated with the assessment/report.

## Teacher and staff authoring responsibilities

- The assigned teacher can record current scores and overall feedback after a confirmed session ends through the teacher booking page.
- Authorized staff can manage baseline/current/potential scores and metric-level feedback through the Filament Session Feedback resource.
- Authorized staff can create/edit Progress Reports in Filament. Setting shared_at publishes the report to the customer; clearing it returns the report to draft/private state.
- created_by records the report author. The author is not assumed to be the assigned teacher.
- Teachers/staff must write evidence-based observations and recommendations. A missing recommendation is left empty rather than fabricated.

See [the score rubric and assessment dimensions](progress-assessments.md) for dimension definitions and general score anchors, and [booking and availability](booking-and-availability.md) for the teacher session workflow.
