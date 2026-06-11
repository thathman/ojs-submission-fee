# Changelog

All notable changes to `submissionFee` are documented in this file.
This project adheres to OJS plugin versioning (`major.minor.revision.build`).

## [1.1.2.0] - 2026-06-11

### Fixed
- **Settings page crashed (HTTP 500).** `settings.tpl` lived at the plugin
  root, but OJS only registers a plugin's Smarty template resource when a
  `templates/` directory exists — so opening the settings modal threw
  `Smarty: Unknown resource type 'plugins-…-submissionFee'`. The template now
  lives in `templates/settings.tpl`.
- **Fee toggle collided with the plugin-enabled flag.** The "enable the
  submission fee" checkbox was stored as the setting `enabled`, the same
  setting name `GenericPlugin` uses to track whether the plugin itself is
  enabled — saving the form with the box unchecked silently disabled the whole
  plugin. The toggle is now stored as `feeEnabled`. (If you had previously set
  the old toggle, simply re-save the settings form once.)
- **Save URL was broken.** The settings form template uses `$pluginName` to
  build its save URL, but the form never assigned it; `fetch()` now assigns it
  (same pattern as core plugins).
- **Pay page rejected the actual author.** The handler authorized via
  `$submission->getData('submitterId')`, but submissions carry no such field
  in OJS 3.5 — it was always null, so every user (including the author) was
  bounced to the dashboard. Authorization now checks for a stage assignment
  on the submission (authors receive one when the wizard starts) or a journal
  manager / site admin role.

## [1.1.1.0] - 2026-06-11

### Fixed
- **Crash on enable.** `PaymentHelper` and `SettingsForm` declared the root
  namespace `APP\plugins\generic\submissionFee` but lived in `classes/`, so their
  fully-qualified class names did not match their path and could not be
  autoloaded — `new PaymentHelper()` / `new SettingsForm()` would fatal the
  moment the plugin was enabled and an author reached submission. Moved both
  files to the plugin root so the namespace matches the path. Caught by a live
  OJS-bootstrap integration test.

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
