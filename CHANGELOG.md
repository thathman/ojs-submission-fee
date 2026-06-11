# Changelog

All notable changes to `submissionFee` are documented in this file.
This project adheres to OJS plugin versioning (`major.minor.revision.build`).

## [1.3.1.0] - 2026-06-11

Versioning note: from this release the scheme is
`MajorFeature.MinorFeature/Upgrade.MajorBug.MinorBug`.

### Fixed
Both found by driving the real wizard in a headless browser:
- **Dedicated Payment step rendered empty.** The `SubmissionFeeStep` Vue
  component was registered at the end of `<body>` — after
  `pkp.registry.init()` boots the app — so Vue silently failed to resolve
  it. Registration now goes through the backend scripts block with
  `STYLE_SEQUENCE_LAST` priority: after `build.js` defines `pkp.registry`,
  before the app boots.
- **Per-step filtering never matched.** Wizard step panels (`.pkpStep`)
  carry no id attribute, so the filter could not tell which step a notice
  clone belonged to and showed all of them (duplicate notices, wrong
  steps). The plugin now publishes the final step order
  (`window.sfWizardSteps`) and the filter maps each notice to its step by
  DOM index. Verified: banner shows only on selected steps, once per step,
  and the Payment step shows a single notice.

## [1.3.0.0] - 2026-06-11

### Added
- **Per-step placement.** The single placement radio is now a set of
  checkboxes: show the fee banner on any combination of wizard steps
  (Details, Upload Files, Contributors, For the Editors, Reviewer
  Suggestions, Review). The wizard clones hook output into every step, so
  the injected script filters visibility client-side and dedupes within a
  step. The legacy `noticePlacement` setting is honoured until re-saved.
- **Dedicated "Payment" wizard step.** Optional own step spliced into the
  wizard just before Review (steps state + a `SubmissionFeeStep` Vue
  component registered before the app boots). Shown only while the fee is
  outstanding.
- **"Begin a Submission" notice.** Optional informational notice (fee
  title, amount, message — no button, since no submission exists yet) under
  the heading of the start page.
- **Confirmation-page notice.** Optional notice with Pay button + popup
  polling on the submission-complete page — the natural surface for
  hold-until-paid journals.

## [1.2.0.0] - 2026-06-11

### Fixed
- **Payments never registered (critical).** `hasPaid()` passed the journal ID
  as the first argument of `OJSCompletedPaymentDAO::getByAssoc()`, which is a
  *user ID* filter — so completed payments were invisible, the wizard notice
  never cleared, hard-block never lifted, and authors could be charged
  repeatedly. The lookup now matches the payment regardless of payer.

### Added
- **Popup payment with live status polling.** The Pay button now opens the
  gateway in a popup; the wizard polls a new JSON endpoint
  (`/submissionFee/status/{submissionId}`) every 4 s, and the moment the
  payment lands the notice flips to a green "payment received" state and the
  popup closes — no more stale wizard tab. (Falls back to a new tab when the
  popup is blocked; polling continues ~30 s after the popup closes to absorb
  the callback race.)
- **Notice placement setting.** Journals choose where the notice renders:
  Review (final) step (default), every wizard step, or both.
- **Editable notice text.** Title and both mode-specific messages can be
  customised in the plugin settings; blank fields fall back to the locale
  defaults.
- **`SUBMISSION_FEE_REQUIRED` mailable.** Sent to the submitting author when a
  submission completes with the fee outstanding (hold-until-paid mode);
  editable under Settings → Workflow → Emails, with `{$submissionTitle}`,
  `{$submissionFeeAmount}`, `{$submissionFeeCurrency}` and
  `{$submissionFeePayUrl}` variables.
- The hold-until-paid listener now skips queueing when the fee is disabled or
  already paid.

### Changed
- Payment status is also surfaced in the workflow **Payment** tab via the
  paymethodSupport plugin (editors get a Paid/Waived/Unpaid switcher there).

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
