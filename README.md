# Happy Order Generator

A WooCommerce extension which automatically generates orders for test sites.

## Summary

This is a set-and-forget order generator which works via the Action Scheduler and Sore API to generate a selected number of orders per hour on a test site. It makes use of the BACS and (if it is in test mode) WooCommerce Stripe Payment gateways.

It will ignore the Stripe gateway if the gateway is in live mode.

This tool is designed to build a steady dataset and is not a stress-tester or bulk order generator. We recommend Smooth Order Generator for bulk generation of orders.

This is
- Set and forget - as long as your cron is reliable, it will generate orders
- It supports simple, variable, subscription and variable subscription products (for these Woo Subscriptions needs to be active). 
- If you have the WooCommerce Stripe plugin running in test mode, Subscriptions are left in a valid, renewable state with a saved payment method. Renewals should just work.
- It is HPOS compatible.
- It does not add database tables.
- It uses PHPFaker to generate test customer data.
- It uses the Store API for cart and order generation via the `cart` and `checkout` endpoints.

## Requirements

The generator only runs on local, development or staging sites, that is, when `wp_get_environment_type()` returns `local`, `development` or `staging`. Set `WP_ENVIRONMENT_TYPE` in `wp-config.php`. Sites that don't set it count as `production`, and on those no orders are generated and the settings page says why. Developers can change the allowed types with the `hog_allowed_environment_types` filter.

While orders are being generated, administrators and shop managers see a warning on every admin screen, with the current rate. Dismissing it hides it until their next login.

## Settings

The whole thing is fairly self-explanatory and is found at WooCommerce > Settings > Order Generator.

### Nothing happens until orders per hour is set to 1 or more. Setting it back to 0 stops generation.

- Enable/Disable – the first setting on the page. Untick it to pause order generation without deactivating the plugin: scheduled actions are removed and nothing is generated until you tick it again. Your other settings are kept. Existing sites stay enabled after updating.
- Orders per hour – for the sake of your sanity, don’t go too high. This isn’t throttled, but you’ll hit the practical timing limits of the Action Scheduler actions at 180 orders an hour, which is plenty too much anyway. 20 orders an hour gives you 175 200 a year. Remember, this is set-and-forget. You may start logging cURL errors if you push it too far over 60 an hour anyway. It will depend on your site.
- Products – you can choose a list of specific products to be used in order generation, or just let the generator happily do its thing. 
- Min and Max order products allows you to decide the size of a cart. Again, pushing this too high might cause performance issues.
- Create user accounts – you can choose to assign orders to existing customers at random, or just do a mix of new and old customers. Only customers created by the generator are reused, never your real customers.
- Bypass SSL verify – This plugin uses the Store API, which means SSL verify is applied automatically when adding items to the cart or checking out. If you’re running a local install, you may want to bypass SSL verify if your certificate is self generated or untrusted. This applies the filter only for Happy Order Generator requests, but don’t use it if your site is in the wild. 
- New customer locales – PHPFaker has a comprehensive list of countries to choose from for your new customers. You may be testing shipping, for instance and if you need some orders from a specific country, this is your setting. 
- Customer naming conventions and Email conventions These orders can look too real for some test orders, so there is an option to set emails and customer names to ensure it is clear that this is test data. 
- Customer emails – WooCommerce emails for generated customers, such as order confirmations, go to one address instead of the customer's: `hog-customer@` your site's domain by default (`hog-customer@example.com` for hosts without a dot, such as `localhost`), or an address you set. You can also choose not to send them at all. Emails for real customers and store staff aren't affected.
- You can set the completed/failed/processing order percentages. The math isn’t going to be 100% but close as makes no difference in the end. Downloadable orders will always complete, and will ignore these settings.

## Other

The meta field `_happy_order_generator_order` is set to `1` on all orders created by the Happy Order Generator. This can be used to clear orders from your database.

Customers created by the generator have the user meta `_happy_order_generator_customer` set to `1`, and the role "Generated customer (Happy Order Generator)" (`hog_generated_customer`), which has no capabilities. Customers created before this version don't have either, and won't be reused for new orders.

