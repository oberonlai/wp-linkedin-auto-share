=== WP LinkedIn Auto Share ===
Contributors: suspended
Tags: linkedin, social, sharing, auto-post, publish
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically share WordPress posts to LinkedIn when published. Supports OAuth 2.0 personal account connection via LinkedIn UGC Posts API.

== Description ==

WP LinkedIn Auto Share automatically publishes your WordPress posts to your LinkedIn profile when they go live. Simply connect your LinkedIn account via OAuth 2.0, customize your post template, and let the plugin handle the rest.

**Features:**

* **One-click OAuth connection** — Securely connect your LinkedIn account directly from the WordPress admin.
* **Customizable post template** — Use `{title}`, `{excerpt}`, `{url}`, and `{tags}` variables to craft your LinkedIn post format.
* **Per-post control** — Choose which posts to share via a meta box in the post editor sidebar.
* **Auto-share mode** — Optionally enable automatic sharing for all new posts without per-post selection.
* **Duplicate prevention** — Each post is shared only once, even if you update it after publishing.
* **Error reporting** — Clear error messages in the post editor if a share fails.

**How It Works:**

1. Create a LinkedIn App in the LinkedIn Developer Portal.
2. Enter your Client ID and Client Secret in the plugin settings.
3. Connect your LinkedIn account via OAuth 2.0 authorization.
4. Publish a post — it gets shared to your LinkedIn profile automatically.

== Installation ==

1. Upload the `wp-linkedin-auto-share` folder to the `/wp-content/plugins/` directory, or install directly through the WordPress plugin screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to **Settings → LinkedIn Auto Share** and follow the setup steps.
4. Create a LinkedIn App at [LinkedIn Developer Portal](https://www.linkedin.com/developers/apps/new).
5. Add your site's redirect URL (shown on the settings page) to the app's Authorized Redirect URLs.
6. Enable the **Share on LinkedIn** and **Sign In with LinkedIn using OpenID Connect** products in the LinkedIn App.
7. Enter your Client ID and Client Secret, then click **Connect LinkedIn Account**.

== Frequently Asked Questions ==

= What LinkedIn permissions are required? =

The plugin requests `openid`, `profile`, `email`, and `w_member_social` scopes. The `w_member_social` scope is required to publish posts on your behalf.

= Can I share to a LinkedIn Company Page? =

Currently, the plugin supports sharing to personal LinkedIn profiles only. Company Page support may be added in a future version.

= Will updating a published post trigger a duplicate share? =

No. The plugin tracks whether a post has already been shared and will not share it again, even if you edit and update the post.

= What happens if sharing fails? =

An error message will appear in the post editor. The error is stored in post meta so you can review it at any time. You can fix the issue (e.g., reconnect your account) and the post will not be reshared automatically — you would need to create a new post.

== Changelog ==

= 1.0.0 =
* Initial release.
* OAuth 2.0 LinkedIn account connection.
* Automatic post sharing via UGC Posts API.
* Customizable post template with variables.
* Per-post and global auto-share options.
* Meta box for individual post control.

== Upgrade Notice ==

= 1.0.0 =
Initial release.

== Third-Party Services ==

This plugin connects to the following external services:

= LinkedIn API =

This plugin communicates with the LinkedIn API to authorize your account and publish posts on your behalf. Data is sent to LinkedIn in the following scenarios:

* **OAuth Authorization** — When you connect your LinkedIn account, your browser is redirected to LinkedIn's authorization page. An authorization code is then exchanged for an access token via LinkedIn's token endpoint.
  * Endpoint: `https://www.linkedin.com/oauth/v2/authorization`
  * Endpoint: `https://www.linkedin.com/oauth/v2/accessToken`
  * Data sent: Client ID, Client Secret, authorization code, redirect URI.

* **Fetching User Profile** — After authorization, the plugin retrieves your LinkedIn display name and person URN to identify your account.
  * Endpoint: `https://api.linkedin.com/v2/userinfo`
  * Data sent: Access token (via Authorization header).

* **Publishing Posts** — When a WordPress post is published, the plugin sends the post content (based on your template) and the post URL to LinkedIn.
  * Endpoint: `https://api.linkedin.com/v2/ugcPosts`
  * Data sent: Post text (title, excerpt, URL, tags as configured), post permalink, your LinkedIn person URN.

**LinkedIn Terms of Service:** [https://www.linkedin.com/legal/l/api-terms-of-use](https://www.linkedin.com/legal/l/api-terms-of-use)
**LinkedIn Privacy Policy:** [https://www.linkedin.com/legal/privacy-policy](https://www.linkedin.com/legal/privacy-policy)

No data is sent to any other third-party service. The plugin does not include any tracking, analytics, or telemetry.
