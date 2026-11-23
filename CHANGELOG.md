# Release Notes for MailChannels

## Unreleased

- Send `User-Agent: mailchannels-craft/<plugin version>` ahead of the SDK's own token so MailChannels can attribute the plugin's Email API traffic.

## 1.0.0 - 2026-11-03

- Clarify best-effort project support through GitHub issues and service support through MailChannels.
- Add a MailChannels mailer adapter for Craft CMS 5.11.4 and newer 5.x versions.
- Keep API keys in server environment variables and persist only variable names.
- Preserve SDK message conversion with synchronous acceptance checks, envelope
  filtering and sanitized errors.
- Add offline contract tests and an isolated installed-Craft smoke test.
