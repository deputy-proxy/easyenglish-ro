# Learner progress assessments

The learner dashboard's **Progress** tab visualizes teacher-recorded development across five dimensions. It is an interpretation aid, not an automated language test or a guarantee of outcomes.

## Assessment dimensions

Scores use a 0–100 scale and are recorded by a teacher after a session.

| Dimension | What the teacher assesses |
| --- | --- |
| Vocabulary | Range, appropriateness and accuracy of words and expressions used in conversation |
| Pronunciation | Clarity and intelligibility of spoken English |
| Fluency | Natural flow, pace and ability to sustain a conversation |
| Grammar | Accuracy and control of useful grammatical structures |
| Speaking confidence | Comfort and willingness to express ideas, respond and keep speaking |

Use the same dimension meanings over time. Scores should reflect observed performance in the session and the learner's demonstrated ability, not attendance, effort alone, or elapsed time.

### General score anchors

Use these anchors to support consistent judgement. The teacher should consider the dimension-specific behaviour above rather than treating the score as a mechanical checklist.

| Score | General interpretation |
| --- | --- |
| 0–20 | Not yet demonstrated reliably |
| 21–40 | Emerging; requires frequent support |
| 41–60 | Developing; succeeds in familiar or supported situations |
| 61–80 | Effective in common situations, with some inconsistency |
| 81–100 | Strong and consistent across a range of situations |

Scores are teacher judgements. Teachers should be able to explain a score through the qualitative feedback attached to the relevant dimension.

## Data semantics

The existing `session_feedback` table stores one row per booking and metric:

- `baseline_score`: the learner's initial reference score for that dimension.
- `current_score`: the teacher's measured score for that dimension for the associated session.
- `potential_score`: a separate estimate of possible future development. It is not an achieved score and is never plotted as progress.
- `feedback`: qualitative teacher observations for the dimension.

The baseline shown in the chart is taken from the earliest chronological non-null baseline recorded for each dimension. Later session feedback must not redefine the initial baseline. Historical session scores remain separate records.

A session is plotted as a complete radar series only when all five dimensions have a non-null `current_score`. Partial assessments remain stored and are not converted into guessed values or misleading polygons.

## Chart behaviour

- The chart shows the baseline and up to the three most recent complete session assessments.
- Selecting a legend entry highlights that assessment and displays its date, five scores and available teacher feedback.
- Older session records remain stored even when they are outside the chart's recent display window.
- The strongest measured improvement is the largest positive point difference between the baseline and latest complete assessment for any one dimension. No aggregate percentage is calculated.
- Equal or lower scores are shown as recorded. The chart must not manufacture growth.
- If there is insufficient complete data, the dashboard explains that the chart is waiting for assessments rather than displaying sample scores.
- Shared narrative progress reports may appear separately from the chart. Only reports explicitly shared with the learner are displayed.

## Privacy

Progress feedback and shared reports are scoped to the authenticated learner's email address and their own bookings. Do not expose another learner's scores, observations, booking details or report content.


## Teacher session feedback

Assigned teachers can open `/teacher/bookings/{reference}` from their authenticated session notification. Access is restricted to the currently assigned teacher. The page displays learner and session preparation details, and the feedback form becomes available only after a confirmed session has ended. Teachers can record current scores for vocabulary, pronunciation, fluency, grammar, and confidence, plus one overall written feedback entry. The records are tied to the booking and identify the teacher who recorded them. A cancelled or reassigned booking cannot be used to submit feedback as the previous teacher.
