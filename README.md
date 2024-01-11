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

## Settings

The whole thing is fairly self-explanatory and is found at WooCommerce > Settings > Order Generator.

### Nothing happens until there is a number in orders per hour.

- Orders per hour – for the sake of your sanity, don’t go too high. This isn’t throttled, but you’ll hit the practical timing limits of the Action Scheduler actions at 180 orders an hour, which is plenty too much anyway. 20 orders an hour gives you 175 200 a year. Remember, this is set-and-forget. You may start logging cURL errors if you push it too far over 60 an hour anyway. It will depend on your site.
- Products – you can choose a list of specific products to be used in order generation, or just let the generator happily do its thing. 
- Min and Max order products allows you to decide the size of a cart. Again, pushing this too high might cause performance issues.
- Create user accounts – you can choose to assign orders to existing customers at random, or just do a mix of new and old customers. 
- Bypass SSL verify – This plugin uses the Store API, which means SSL verify is applied automatically when adding items to the cart or checking out. If you’re running a local install, you may want to bypass SSL verify if your certificate is self generated or untrusted. This applies the filter only for Happy Order Generator requests, but don’t use it if your site is in the wild. 
- New customer locales – PHPFaker has a comprehensive list of countries to choose from for your new customers. You may be testing shipping, for instance and if you need some orders from a specific country, this is your setting. 
- Customer naming conventions and Email conventions These orders can look too real for some test orders, so there is an option to set emails and customer names to ensure it is clear that this is test data. 
- You can set the completed/failed/processing order percentages. The math isn’t going to be 100% but close as makes no difference in the end. Downloadable orders will always complete, and will ignore these settings.

## Other

The meta field `_happy_order_generator_order` is set to `1` on all orders created by the Happy Order Generator. This can be used to clear orders from your database.

