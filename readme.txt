=== Plugin Risk Radar ===
Contributors: VictorNeri
Tags: security, plugins, audit, maintenance, vulnerability
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Know which of your installed plugins are abandoned, outdated, or missing from WordPress.org before they become your next security incident.

== Description ==

**90% of WordPress hacks start with a vulnerable plugin — most site owners have no idea which of their plugins are actually at risk.**

Plugin Risk Radar scans every plugin installed on your site and flags the ones that need your attention:

* 🔴 **Red** — Not updated in 12+ months. Unmaintained plugins are a leading cause of WordPress compromises.
* 🟡 **Yellow** — Not found on WordPress.org (could be a premium plugin, or one that's been quietly removed from the repository).
* 🟢 **Green** — Actively maintained, no action needed.

No configuration required. Install it, click "Scan Now," and get a clear, color-coded report in your dashboard.

= Why this matters =

Recent WordPress supply-chain incidents have shown that even "trusted" plugins can become dangerous after a change in ownership or a long gap in maintenance. Plugin Risk Radar gives you the visibility to catch these risks early, instead of finding out after a breach.

= Features (Free) =

* One-click plugin risk scan
* Color-coded dashboard (red / yellow / green) — closed/removed WP.org plugins flagged red
* Plugin ownership-change detection (author and contributor tracking)
* Acknowledge known risks to silence repeat alerts
* Daily automatic re-scan
* Email alerts for newly high-risk plugins only (no daily spam)
* Zero configuration, zero external accounts required

= Features (Pro) =

Upgrade to Plugin Risk Radar Pro for:

* Vulnerability database cross-referencing (known CVEs)
* Slack notifications
* Downloadable PDF audit reports — great for agencies managing multiple client sites

[Learn more about Pro](https://example.com/plugin-risk-radar-pro)

== Installation ==

1. Upload the `plugin-risk-radar` folder to `/wp-content/plugins/`, or install directly through the WordPress Plugins screen.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **Risk Radar** in your admin sidebar and click "Scan Now."

== Frequently Asked Questions ==

= Does this slow down my site? =

No. Scans run in the background via WordPress's built-in cron system and results are cached, so there's no impact on your site's front-end performance.

= Will this flag premium plugins as dangerous? =

No. Premium/custom plugins that aren't on WordPress.org are marked yellow (informational) rather than red, since not being on WordPress.org doesn't by itself mean a plugin is unsafe.

= Does this fix vulnerabilities automatically? =

No. Plugin Risk Radar identifies risk so you can make an informed decision — update, replace, or remove the plugin yourself.

== Screenshots ==

1. The main dashboard showing color-coded plugin risk levels.

== Changelog ==

= 1.0.2 =
* New: 0–100 risk score per plugin, combining staleness, WP version compatibility lag, support thread health, install base size, and ownership-change signal.
* New: Score displayed beneath the status badge with a color-coded indicator (green/yellow/red) and a hover tooltip showing the factor breakdown.
* Scanner now fetches additional WP.org API fields: tested, active_installs, support_threads, support_threads_resolved.

= 1.0.1 =
* Fix: use UTC timestamps (time()) instead of local-offset current_time('timestamp') for accurate staleness checks.
* Fix: closed/removed WordPress.org plugins now flagged red instead of yellow.
* Fix: daily email no longer re-sends for persistently-red plugins — alerts fire once per new detection.
* New: Acknowledge button per plugin row — silences alerts and dims the row for known/accepted risks.
* New: Manual scan rate-limited to once per 5 minutes to prevent API hammering.

= 1.0.0 =
* Initial release.
