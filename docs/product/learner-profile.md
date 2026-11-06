# Learner profile

## Purpose and location

The authenticated customer dashboard includes **My learning profile** (/dashboard?view=profile). It is the learner-facing summary of current learning context, not a replacement for account settings or the teacher assessment workflow.

The existing account profile settings page (/settings/profile) is the single edit surface. The dashboard links to it rather than duplicating update logic.

## Current profile fields and source of truth

The existing users columns are sufficient; this feature does not introduce a separate goals table or duplicate profile fields.

| Field | Purpose | Customer editing |
| --- | --- | --- |
| users.english_level | Learner's own CEFR-level estimate | Editable, optional, A1–C2 |
| users.session_goal | Main learning objective / situation the learner wants to practise | Editable, optional, up to 500 characters |
| users.session_notes | Additional learning preferences and context that may help a teacher prepare | Editable, optional, up to 5,000 characters |

The current profile values describe the learner now. They are not historical session data. Updating or clearing a profile field must never rewrite bookings.english_level, bookings.session_goal, or bookings.notes snapshots.

session_notes is learner-supplied learning context, not a staff-only note field. Customers should avoid entering sensitive personal information. Access remains governed by the existing account, assigned-session, and staff authorization boundaries.

## Current English level and assessment precedence

The dashboard displays the most recent non-null overall_level from a published ProgressReport that:

1. belongs to one of the authenticated learner's explicitly owned bookings (or an eligible legacy booking through the documented verified-identity fallback);
2. has a non-null shared_at; and
3. has a non-null overall_level.

The report's shared_at timestamp and title are displayed as the assessment source/date. The UI labels it as a published report and does not assume the report author is necessarily the assigned teacher. A newer draft, an unshared report, a report without an overall level, a score in session_feedback, or a report belonging to another customer cannot replace this value.

If no qualifying shared report exists, the dashboard displays users.english_level as **Self-reported**. If neither source exists, it displays a non-blocking empty state. It never calculates a CEFR level from the five numeric feedback dimensions.

When an assessed level exists, the learner's self-reported level remains visible as a separate value if present. Teacher assessment does not silently overwrite the learner's own profile.

## Goals and preferences

The current session_goal field is the main learning objective. The existing optional session_notes field captures supplementary topics, situations, and learning preferences. Together they support the current product scope without introducing a complex goal/milestone system.

Optional fields may be empty. Profile completeness does not block booking or account use.

## Privacy and authorization

- Customers can only edit their own authenticated account profile.
- The dashboard profile page uses the authenticated user's own User record and customer-owned booking scope.
- Only published/shared progress reports can contribute an assessed level.
- Teacher feedback scores are not converted into CEFR levels.
- Do not display another learner's report title, level, summary, feedback, or assessment date.
- Existing settings, verification, password, passkey, and two-factor flows remain the authoritative account/security surfaces.

## Validation and localization

English level accepts only A1, A2, B1, B2, C1, or C2. The main goal is limited to 500 characters and additional context to 5,000 characters. All three learning fields are optional after registration.

The dashboard and settings copy is available in Romanian and English. The profile page follows the existing Blade/Livewire, Tailwind, dark-mode, and responsive conventions.


The report publication, assessment completeness, history, and recommendation rules are defined in [Learner progress and assessments](learner-progress-and-assessments.md). The learner profile and Progress view share the same published-report source for assessed CEFR level.
