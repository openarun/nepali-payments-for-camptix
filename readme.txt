=== Nepali Payments for CampTix ===
Contributors: arunpyasi
Tags: camptix, nepali, payments, gateway
Requires at least: 3.5
Tested up to: 7.0
Stable tag: 1.0.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Add Nepali payment gateway support to CampTix for accepting payments in Nepali Rupees (NPR).

== Description ==

Nepali Payments for CampTix adds Nepali payment gateways support to the CampTix plugin, allowing you to accept payments in Nepali Rupees (NPR).

= Supported Payment Gateways =
* [Khalti](https://khalti.com/)

= Features =
* Seamless integration with CampTix
* Supports test mode (sandbox) for development
* Secure payment processing through Khalti
* Automatic order status updates
* Supports NPR currency

= Important Note =
CampTix plugin needs to be installed and activated for the Camptix Nepali Payments gateway to work.

== Installation ==

1. Upload `nepali-payments-for-camptix` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to `Tickets -> Setup` in your WordPress admin area
4. Set the currency to NPR
5. Go to `Payment` tab and Enable the payment gateway.
6. To enable and configure Khalti payment gateway:
   * Enter your Khalti Merchant Key
   * Set Reference Code (optional)
   * Enable/disable sandbox mode for testing

== Frequently Asked Questions ==

= How do I test the payment gateway? =

1. Enable sandbox mode in the plugin settings
2. Use Khalti's test credentials from https://docs.khalti.com/getting-started/#3-test-environment
3. Make a test purchase to verify the integration

= Which currencies are supported? =

Currently, only Nepali Rupees (NPR) is supported as that is the only currency accepted by Khalti.

== Screenshots ==

Nothing here

== Changelog ==

= 1.0.2 =
* Add amount_breakdown and product_details with validation fix for phone numbers
* Minor bug fixes

= 1.0.1 =
* Fix minor bugs

= 1.0.0 =
* Initial release
* Khalti payment gateway integration
* Sandbox mode support
* NPR currency support

== Upgrade Notice ==

Nothing here