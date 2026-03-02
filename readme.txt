=== Assist My Shop ===
Contributors: positive-studio
Tags: ai, chatbot, woocommerce, support, ecommerce
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.1.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered customer support plugin for WooCommerce and WordPress with a customizable on-site chat widget.

== Description ==

Assist My Shop adds an AI assistant widget to your storefront and syncs selected store content so the assistant can answer shopper questions.

Features:

* Frontend chat widget for customers
* Product-aware recommendations and links
* Background sync for selected content types
* Admin styling controls with presets
* WooCommerce-aware currency and cart links

=== External services ===

This plugin connects to an external API service provided by Assist My Shop.

Service endpoint:

* `https://api.assistmyshop.com/api/v1`

When API calls are made, the plugin may send:

* Store URL
* Store metadata (store name, WooCommerce version, currency)
* Selected synced content (for example products, posts, pages)
* Product metadata (for example title, description, price, categories, tags, attributes, image URL)
* Order sync payload fields when enabled by plugin flow (for example order id, status, total, and order items)
* Chat request data (message text and session id)
* Store API key configured in plugin settings

These requests are required for core plugin functionality (content sync and AI chat responses).

Service terms:

* https://assistmyshop.com/terms

Service privacy policy:

* https://assistmyshop.com/privacy

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/assist-my-shop` directory, or install via the WordPress plugins screen.
2. Activate the plugin through the `Plugins` screen in WordPress.
3. Go to `Settings -> Assist My Shop`.
4. Enter your API key.
5. Save settings and run sync.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

WooCommerce is recommended for full product use-cases. The plugin can also sync other selected post types.

= Where do I get an API key? =

Create or open your store in the Assist My Shop SaaS dashboard and copy the store API key into plugin settings.

== Changelog ==

= 1.1.7 =

* Chat styling and preset improvements
* Product card and cart-link flow updates
* Sync and admin UX improvements
