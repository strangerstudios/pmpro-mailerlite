=== Paid Memberships Pro - MailerLite Add On ===
Contributors: strangerstudios, flintfromthebasement
Tags: pmpro, mailerlite, email marketing, membership, sync
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync your PMPro members with MailerLite groups.

== Description ==

This plugin integrates Paid Memberships Pro with MailerLite using the MailerLite API. Automatically add members to MailerLite groups based on their membership level.

= Features =

* **Simple API Key Authentication** — Just paste your API key from the MailerLite dashboard.
* **Group Management** — Assign MailerLite groups to each membership level. Members are automatically added when they gain a level and optionally removed when they lose it.
* **Non-Member Groups** — Automatically subscribe new users without a membership level to designated groups.
* **Custom Fields** — Membership level ID and name are stored as custom fields on each subscriber.
* **Profile Sync** — Optionally sync subscriber data when a user updates their WordPress profile.
* **Background Processing** — Uses PMPro Action Scheduler for non-blocking sync operations.
* **Developer Friendly** — Filter hooks for customizing subscriber data and fields.

= Hooks =

* `pmproml_subscriber_data` — Modify subscriber data before sending to MailerLite.
* `pmproml_subscriber_fields` — Add or modify fields sent with the subscriber.

== Installation ==

1. Upload the `pmpro-mailerlite` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to Memberships > MailerLite in your WordPress admin.
4. Enter your MailerLite API key (found under Integrations > MailerLite API in your MailerLite dashboard).
5. Save settings and configure groups for each membership level.

== Frequently Asked Questions ==

= Where do I get my API key? =

1. Log in to your MailerLite account.
2. Go to Integrations > MailerLite API.
3. Click "Generate new token".
4. Copy the token into the plugin settings.

= What are MailerLite groups? =

Groups in MailerLite are equivalent to lists or audiences in other email marketing tools. They are the primary way to organize and segment your subscribers. This plugin maps PMPro membership levels to MailerLite groups.

= Does this sync existing members? =

The plugin syncs members when their membership level changes or when they update their profile. To sync all existing members, trigger a profile save or use a bulk sync tool.

= What happens when a member cancels? =

Depending on your settings, the member can be removed from the groups associated with their old level. If non-member groups are configured, they will be added to those instead.

== Changelog ==

= 0.1 - 2026-04-13 =
* Initial release.
* MailerLite API integration with Bearer token authentication.
* Group assignment per membership level.
* Non-member group support.
* Custom fields for membership level data.
* Background sync via PMPro Action Scheduler.
* Profile update sync (configurable).
* Debug logging.
