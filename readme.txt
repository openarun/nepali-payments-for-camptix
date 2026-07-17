=== Nepali Payments for CampTix ===
Contributors: arunpyasi
Tags: camptix, nepali, payments, gateway
Requires at least: 4.7.0
Tested up to: 7.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Add Nepali payment gateway support to CampTix for accepting payments in Nepali Rupees (NPR).

== Description ==

Nepali Payments for CampTix adds Nepali payment gateways support to the CampTix plugin, allowing you to accept payments in Nepali Rupees (NPR).

= Supported Payment Gateways =
* [Khalti](https://khalti.com/)
* [Fonepay QR](https://fonepay.com/)

= Features =
* Seamless integration with CampTix
* Supports test mode (sandbox) for development
* Secure payment processing through Khalti and Fonepay QR
* Fonepay QR checkout with on-page QR display and automatic payment confirmation
* Automatic order status updates
* Supports NPR currency

= Important Note =
CampTix plugin needs to be installed and activated for the Camptix Nepali Payments gateway to work.

== Installation ==

1. Upload `nepali-payments-for-camptix` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to `Tickets -> Setup` in your WordPress admin area
4. Set the currency to NPR
5. Go to `Payment` tab and enable the payment gateway you want to use.

= Khalti =
* Enter your Khalti Merchant Key
* Set Reference Code (optional)
* Enable/disable sandbox mode for testing

= Fonepay QR =
* Enter your Fonepay merchant username and password
* Paste your RSA private key (PEM) for request signing
* Enter your Terminal ID
* Set Reference Prefix (optional)
* Enable/disable sandbox mode for testing

== Frequently Asked Questions ==

= How do I test the payment gateway? =

**Khalti**
1. Enable sandbox mode in the Khalti payment settings
2. Use Khalti's test credentials from https://docs.khalti.com/getting-started/#3-test-environment
3. Make a test purchase to verify the integration

**Fonepay QR**
1. Enable sandbox mode in the Fonepay QR payment settings
2. Use Fonepay UAT credentials and terminal details provided by Fonepay
3. Complete a test purchase and scan the displayed QR with a supported banking or Fonepay app

= Which currencies are supported? =

Currently, only Nepali Rupees (NPR) is supported.

== Screenshots ==

Nothing here

== Changelog ==

= 1.1.0 =
* Add Fonepay QR payment gateway
* Harden Khalti payment return and status handling
* Add pre_attendee_timeout hook for Khalti and Fonepay

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

= 1.1.0 =
Adds Fonepay QR and improves Khalti payment reliability.
