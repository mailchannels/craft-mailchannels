# MailChannels for Craft CMS

**Unreleased integration candidate.** Tested locally with Craft 5.11.4 and PHP
8.3. It is not listed in the Craft Plugin Store and has not sent a live test email.

Adds **MailChannels** to Settings → Email → Transport Type. Craft system emails
and plugins that use Craft's mailer are sent through the MailChannels Email API.
Plugins that implement their own sending service are unaffected.

## Installation and configuration

Until a release is published, use a Composer path repository pointing to this
checkout in a development Craft project. Require
`mailchannels/craft-mailchannels:@dev`, then run:

```sh
php craft plugin/install mailchannels
```

The package requires Craft `^5.11.4`, PHP `^8.2`, and the official
`mailchannels/mailchannels-php` SDK `^2.2`. Only the versions in the validation
section below have been executed here. Craft 4 and Craft 6 are not supported.

1. Complete [MailChannels account and sending-domain setup](https://docs.mailchannels.com/email-api/overview).
2. Store the API key in a server environment variable such as
   `MAILCHANNELS_API_KEY`. Provision it on web and queue/console workers. Keep it
   out of source control and public web directories.
3. In Craft's Email settings, select **MailChannels** and enter the environment
   variable's **name**, without a `$` prefix. Never paste the API key into this
   field. Only the name is saved in Craft project configuration.
4. Set Craft's sender address/name and reply-to address to values authorized for
   the MailChannels account. Save the settings.
5. Craft's **Test** action sends a **real email**. Use an approved recipient only.
   This adapter has no dry-run mode: a validation-only response must not cause
   Craft to report that a password reset or other system email was sent.

A missing or invalid environment setting fails closed; there is no SMTP fallback
inside this adapter. Removing or disabling a selected transport plugin can cause
Craft itself to choose its fallback mailer. Change Email settings before removal.

## Message handling

Uses the official PHP SDK's Symfony Mailer transport to convert sender, recipients,
reply-to, plain text/HTML, attachments and supported MailChannels option headers.
The adapter always uses synchronous `/send`, overriding
`X-MailChannels-Send-Async` on a cloned message. A success means the API returned
HTTP 202 and one `sent` acceptance result, not final delivery. Use MailChannels
records or delivery events to establish delivery.

Explicit Symfony envelope overrides are honored; original CC/BCC addresses that
are not in that envelope are excluded. The original message is not modified.
Only the first Reply-To address is supported by the underlying SDK. Review SDK
limitations before using custom MIME structures or option headers. Do not put
secrets or untrusted provider-control headers into user-authored messages.

The production endpoint is fixed to `https://api.mailchannels.net/tx/v1`; the SDK's
base-URL environment override is not used. The HTTP client has a 10-second connect
and 30-second request timeout, TLS verification enabled, and redirects disabled.
Neither the adapter nor its client installs retry middleware. Craft/plugin queues
may retry independently: inspect those policies before use. An ambiguous timeout
or failure may follow acceptance; inspect delivery records before retrying.

Failure messages exposed to Craft omit HTTP bodies and underlying exception
chains, which could contain addresses, API keys or DKIM material. Consequently,
troubleshooting requires MailChannels delivery records. Craft and other installed
plugins can log message data independently; review their logging configuration.

## Validation

The checked-in Composer locks capture Craft **5.11.4**, MailChannels SDK **2.2.0**,
Symfony Mailer **7.4.19**, Guzzle **7.15.5** and PHPUnit **10.5.66**.

```sh
docker build -t visibility-craft-tests:php83 -f tests/Dockerfile .
docker run --rm -v "$PWD:/app" visibility-craft-tests:php83 composer install --no-interaction
docker run --rm --network none -v "$PWD:/app" visibility-craft-tests:php83 php vendor/bin/phpunit --bootstrap tests/bootstrap.php tests
python3 tests/docker-smoke.py
```

The unit/integration suite runs against real Craft/Yii classes and the published
SDK, using a fake HTTP client: **7 tests, 64 assertions**. It covers registration,
settings, MIME conversion, envelope overrides, attachments, failed/malformed
results, HTTP errors, timeouts, sanitization and one request per attempt.

The Docker smoke test installs Craft and the plugin into a disposable MySQL
instance. Its internal network has no outbound access. Nine checks exercise saved
settings, actual editable/read-only Twig rendering and Craft's mailer success and
failure behavior. It also scans project config and logs for the fixture key and
cleans up its database container/network. Composer needs network access during
installation; the execution tests do not contact MailChannels.

Browser UI review, a minimum-PHP-version run, authorized live sending, release
operator assignment, and Plugin Store approval remain outstanding. No production
compatibility or delivery guarantee is implied by these local tests.

## Release and support

This is an unreleased candidate. The confirmed support contact is
**dev@mailchannels.com**. A release operator must be assigned before release.
Please use this repository's issues for code review. For MailChannels account support, use
[MailChannels Support](https://support.mailchannels.com/hc/en-us/requests/new).

Publishing requires a public GitHub repository connected to a company Craft
Console organization, a reviewed icon/description, a release tag, public docs and
changelog, and Craft's approval. See the
[official Plugin Store guide](https://craftcms.com/docs/5.x/extend/plugin-store).

MIT license. The included envelope icon is original artwork for this integration.
