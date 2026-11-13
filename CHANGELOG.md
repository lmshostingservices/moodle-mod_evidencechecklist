# Changelog

## [v1.2.2] - 2026-11-13

### Changed
- Version bump for resubmission to the Moodle plugins directory (review MMRT-259). Includes all v1.2.1 fixes.
- Issue tracker is now public: https://github.com/lmshostingservices/evidence-checklist/issues

## [v1.2.1] - 2026-11-09

### Fixed
- Added missing language strings: the "result released" message provider (messageprovider:resultreleased) and the
  privacy descriptions for the gap plan close fields (closedby, closenote, timeclosed).
- The privacy provider now declares the LMS Labs activation check sent to lms-labs.com (site ID, API key and plugin ID).
  No personal or learner data is sent.
- Release ZIP now includes explicit directory entries.

## [v1.2.0] - 2026-10-28

### Added
- One video, photo or audio file can now cover several criteria. Tap "Also covers…" on any evidence file (or on
  the toast straight after uploading) and tick the other criteria it shows. The file is stored once, appears on
  every ticked row on both the assessor's and the student's screens, and is listed under each criterion in the web
  and PDF evidence reports with "From 1.1" so an auditor can see where it was recorded.
- The first time, the rest of the same section in the same column is ticked for you; untick anything the file does
  not clearly show. Tick all and Clear all buttons, and a running count.
- "Remove link" on a linked row, and links are removed automatically when the original file is deleted.
- Every link and unlink is written to the cell's audit trail.

### Notes
- Linking never marks a criterion. The assessor still marks each one, so the rules of evidence are kept.
- Links are included in course backup and restore, and in privacy export and delete.

## [v1.1.0] - 2026-10-28

### Added
- Evidence pad on every row: photo, video, audio and comment in one tap, without opening the side panel.
- Quick comment pop-up with one-tap phrases, recent comments and dictation. Enter saves.
- Audio recording straight from the row, with a recording bar and one-tap stop.
- Keyboard shortcuts for the whole marking flow (S, N, O, arrows, next empty cell, row and section S, undo,
  photo, video, audio, comment, timer, go to item, sign-off) and a Shortcuts menu at the top right.
- Previous and next student buttons in the header (Shift + arrow keys).
- Help icon with practical advice on every input across the plugin, including every settings field.

### Fixed
- Controls keep their own colours on hover in themes such as Academi that recolour buttons and links.

## [v1.0.0] - 2026-10-27

### Added
- Assessor-led practical observation activity for Australian RTOs. No AI, no external services.
- Fast paste: plain lists, Excel rows or the ChatGPT prompt output (groups, criteria, columns, benchmarks,
  gap tips, verbal questions and the full unit mapping) with preview before import.
- Copy prompt and Download prompt buttons for the bundled checklist-writer prompt.
- Automatic numbering (1, 1.1, 1.2) everywhere; reference image per criterion.
- Marking grid with only the columns the unit needs (for example Adult and Infant), set by the prompt from the
  unit and editable on the Build checklist page (rename, times observed, add, remove when empty). Criteria are
  observed in All columns, Once, or named columns. One-tap S / NYS / clear, press-and-hold panel, Not observed,
  timer, comments, private notes and browser dictation. Every row and cell is the same size.
- All S for the whole checklist, a group or a column, with 10-second undo and bulk review at sign-off.
- Evidence capture per cell: photos compressed in the browser, video and audio upload or in-browser recording,
  optional ffmpeg compression with poster frames.
- Gap plans with strategy and re-observation date; gaps open and close automatically.
- Pre-assessment statements confirmed by the student (own device or assessor tablet) with time and method.
- Session details and candidate photo with identity check (photo ID sighted, or a matching Verify ID record when
  that plugin is installed).
- Mapping report with big-number summary, requirement and reverse views, inline editing, approval and CSV;
  optional lock that blocks assessing until mapping is complete.
- Live student view: the student's checklist and progress bar update within seconds of each mark.
- One-step sign-off with e-signatures (drawn or typed), declaration, record fingerprint, gradebook, completion and
  notification. Reassessment carries satisfactory cells forward.
- Practical Evidence Report on screen and as a hand-laid PDF (embedded Plus Jakarta Sans, cards, result pills,
  photos, video poster frames with centred QR codes, audio QR tiles, signatures, page numbers). Register with progress, outcomes, confirmations and identity per student, plus CSV.
- Assessor guidance tips as quiet chips with sources, a full guide and an on/off switch per assessor.
- Light design system on every page: 16px type, 48px controls, thin full outlines, phone action bar.
- Privacy API, backup and restore with user data and files, course reset, events, PHPUnit tests.
