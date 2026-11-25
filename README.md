# ChatKart – Click to Chat Button & Cart Checkout for WooCommerce
**Contributors:**  hedows
**Tags:**  whatsapp, woocommerce, click to chat, chat button, order
**Requires at least:**  5.8
**Tested up to:**  7.1
**Requires PHP:**  7.4
**Stable tag:**  1.0.0
**License:**  GPLv2 or later
**License URI:**  https://www.gnu.org/licenses/gpl-2.0.html

Add a floating WhatsApp chat button to your site and let WooCommerce customers order, share and check out their cart on WhatsApp.

## Description

**ChatKart** puts WhatsApp at the centre of your store. Visitors can start a chat from any page, and WooCommerce customers can send you their order or their whole cart as a ready-made WhatsApp message.

ChatKart uses WhatsApp click-to-chat links (`wa.me`). It does not need the WhatsApp Business API, a Meta developer account or any monthly fee. Everything in this plugin is free and fully working – there are no locked features, trial periods or licence keys.

## Chat on every page (works without WooCommerce)

* Floating WhatsApp button, left or right, with your own colour, size, label, effect (pulse, bounce, shake, ring) and "hide on mobile / desktop" option
* Optional chat popup with greeting, agent name and role, and a QR code for desktop visitors
* Call-to-action bubble with delay
* Page-specific number and message in the editor box of any page, post or product, or "always show / always hide" per page
* Display rules: show on selected post types, include or exclude URLs (wildcards), show to logged-in or logged-out visitors only – evaluated in the browser, so they work with page caching
* Inline button via shortcode, Gutenberg block, Elementor widget, or a link to `#whatsapp` (opens WhatsApp) / `#chatkart-chat` (opens the chat popup)
* Prefilled message with placeholders such as {site_name}, {page_title} and {page_url}

## WhatsApp ordering for WooCommerce

* "Order on WhatsApp" button on shop loops, product pages, cart page and checkout page
* Simple and variable products, quantity and chosen variation included in the message
* Popup order form (name, phone, email, address, note) or direct WhatsApp message
* Cart popup: customers review and edit their cart, then send it on WhatsApp
* Three popup designs: classic card, bottom sheet, dark
* "Share on WhatsApp" button for products
* Show for all products, selected categories or products, or per-product override
* Hide the Add to cart button if you want WhatsApp-only selling (visual only – checkout stays available)
* Message templates with placeholders for items, totals and customer details
* Optional email copy of each WhatsApp order to the shop and to the customer
* Button to message a customer about an existing order, with a status template, directly from the WooCommerce order screen
* HPOS (custom order tables) and Cart / Checkout blocks compatible
* Optional tidy-up of the WooCommerce admin menu (hide sections you do not use)

## Tracking and statistics

* Anonymous click counter on your own site (date, agent and page only – no names, no IP addresses, no cookies)
* Optional events pushed to your existing Google Analytics 4, Google Tag Manager `dataLayer` or Meta Pixel when someone starts a chat or sends an order
* Settings import / export

## ChatKart Pro (optional, paid, separate plugin)

If you need more, **ChatKart Pro** is a separate paid plugin from the same author. It is not required and this free plugin contains none of its code. Pro adds:

* Multiple agents with business hours, offline message, dark and grid chat popup, auto-open
* Lead capture form with leads manager and CSV export, full analytics breakdown
* Creation of real WooCommerce orders from WhatsApp orders, WhatsApp-only mode (disable checkout), WhatsApp Order payment method
* All 10 popup templates, custom popup HTML / CSS / JS, floating cart icon
* Advanced display rules and triggers (roles, country, traffic source, date range, scroll, exit intent, time delay)
* Random and round-robin WhatsApp numbers, icon library
* Google Ads conversions, campaign source (UTM) in messages, webhooks for Zapier, Make and n8n
* WhatsApp Cloud API automation: order messages, abandoned cart recovery, back-in-stock alerts and review requests

Learn more at [hedows.com/chatkart](https://hedows.com/chatkart).

## External services

This plugin links to **WhatsApp** (operated by Meta Platforms, Inc.) so visitors can contact you.

* When a visitor clicks a ChatKart button, their browser opens `https://wa.me/<your number>?text=<prefilled message>` (or `web.whatsapp.com` / the WhatsApp app). The prefilled message may contain the page title and URL, and – for WhatsApp orders – the products, totals and the details the customer typed into the order form. This happens only after the visitor clicks, and the data goes from the visitor's browser to WhatsApp; ChatKart does not send it to any server of its own.
* The QR code shown in the chat popup is generated locally in the visitor's browser.
* WhatsApp Terms of Service: https://www.whatsapp.com/legal/terms-of-service – Privacy Policy: https://www.whatsapp.com/legal/privacy-policy

If you enable the Google Analytics, Google Tag Manager or Meta Pixel options, ChatKart only calls the `gtag`, `dataLayer` or `fbq` objects that your own tag plugins have already loaded. ChatKart itself does not load any third-party script, font or image.

## Privacy

* The statistics table stores time, source, agent name and page URL – never personal data. Entries older than 400 days are deleted automatically.
* The WhatsApp order form data is placed in the WhatsApp message and, if you enable it, emailed to the shop and to the customer. It is not stored in your database by this plugin.
* A suggested text is added to Settings > Privacy (Privacy Policy Guide) to help you document this.

## Trademark notice

ChatKart is an independent plugin and is not affiliated with, endorsed by or sponsored by WhatsApp or Meta. WhatsApp is a trademark of Meta Platforms, Inc.

## Installation

1. Upload the `chatkart` folder to `/wp-content/plugins/`, or install it from Plugins > Add New.
2. Activate **ChatKart**.
3. Go to **ChatKart** in the admin menu (or Plugins > ChatKart > Settings), enter your WhatsApp number with country code (digits only, e.g. 923001234567) and save.
4. Optional: open the *WooCommerce* tabs to configure order buttons, messages and popup design.

## Frequently Asked Questions
<details>
<summary><b>Do I need WooCommerce?</b></summary>
<br>
  No. The floating chat button, chat popup, shortcode, block and Elementor widget work on any WordPress site. The ordering features appear when WooCommerce is active.
</details>

<details>
<summary><b>Do I need the WhatsApp Business API?</b></summary>
<br>
  No. ChatKart uses WhatsApp click-to-chat links. It cannot send messages automatically – the customer or you tap "Send" in WhatsApp.
</details>

<details>
<summary><b>Does it create WooCommerce orders?</b></summary>
<br>
  The free plugin sends the order details to WhatsApp (and optionally by email). It does not create orders in WooCommerce – that is a ChatKart Pro feature.
</details>

<details>
<summary><b>Is anything locked or limited to a trial?</b></summary>
<br>
  No. Every setting you see in this plugin works. Paid features live in a separate plugin.
</details>

<details>
<summary><b>How do I add a button manually?</b></summary>
<br>
  Use the shortcode `[chatkart_chat text="Chat with us"]` (optional `phone`, `message`, `icon`, `class`), the ChatKart block in the editor, the Elementor widget, or add a link to `#whatsapp`. For a WooCommerce product order button use `[chatkart_button id="123"]`.
</details>

<details>
<summary><b>Can I change the number per page?</b></summary>
<br>
  Yes. Open any page, post or product and use the ChatKart box in the editor sidebar.
</details>

<details>
<summary><b>Does it work with caching plugins?</b></summary>
<br>
  Yes. Display rules are evaluated in the browser, and the order form requests its security token by AJAX.
</details>

<details>
<summary><b>Where is my data stored?</b></summary>
<br>
  Only the plugin settings and an anonymous click counter are stored. Uninstalling the plugin removes them.
</details>

## Changelog
<details>
<summary><b>1.0.0</b></summary>
<br>
  * First public release.
</details>

### Upgrade Notice
<details>
<summary><b>1.0.0</b></summary>
<br>
  First public release.
</details>
