# Changelog

All notable changes to `submissionFee` are documented in this file.
This project adheres to OJS plugin versioning (`major.minor.revision.build`).

## [1.1.0.0] - 2026-06-11

### Added
- Author-facing fee notice rendered inside the submission wizard's **Review**
  step, via the native `Template::SubmissionWizard::Section::Review` hook.
  Shows the fee amount, currency, a mode-aware explanation, and a **Pay submission
  fee** button. No Vue build and no theme template edits required; upgrade-safe
  across 3.5.x point releases.
- `PaymentHelper::formattedAmount()` for display formatting.
- Locale keys: `notice.title`, `notice.amount`, `notice.bodyHardBlock`,
  `notice.bodyHoldUntilPaid`, `notice.payNow`.
- `LICENSE` (GPL-3.0), `CHANGELOG.md`, `.gitignore` for standalone distribution.
- `version.xml` now declares `<compatibility>` for OJS 3.5.0.0–3.5.0.3 and uses
  the plugin-version DTD.

### Changed
- Added copyright/licence headers to all PHP source files.
- Rewrote `README.md` for distribution (install, configuration, verification,
  compatibility, known limitations).

### Removed
- Deleted dead `classes/SubmissionFeeListener.php` (was never registered; the
  plugin wires the `SubmissionSubmitted` listener inline in `register()`).

## [1.0.0.0] - 2026-05-29

### Added
- Initial release: submission-time fee using the native OJS payment subsystem.
- Two enforcement modes: **hard block** (`Submission::validateSubmit`) and
  **hold until paid** (`SubmissionSubmitted` event + `submissionFeeOutstanding`
  flag).
- Author pay page `/{journal}/submissionFee/pay/{submissionId}` handing off to
  the journal's configured payment method plugin.
- Journal-level settings: enable, amount, currency, enforcement mode.
