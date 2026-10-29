# Zero_Stripe

Stripe for Zero Checkout, two methods:

- **Credit Card (Stripe)** `zero_stripe`: Stripe's Payment Element in the
  checkout, a PaymentIntent sized to the cart, captured when the order is
  placed (`Model\Standard`, `Controller\Standard\Intent`).
- **Pay Later, Wallets and More (Stripe)** `zero_stripe_checkout`: Stripe
  Checkout, the hosted page with every method enabled in the Stripe
  Dashboard; the order waits in pending payment until the customer is back
  or the webhook arrives (`Model\Checkout\*`, `Controller\Checkout\*`).

Settings: Stores > Configuration > Sales > Payment Methods > Stripe. Keys per
mode (test/live), an optional hold (authorize now, capture on invoice) and
the webhook secret for Stripe Checkout.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-stripe
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-checkout` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

<a href="https://vilmate.com"><picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo-dark.svg">
  <img src="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo.svg" alt="Vilmate" height="34" align="right">
</picture></a>

Zero is made by [Fixstacks](https://fixstacks.com) in partnership with [Vilmate](https://vilmate.com), a software development company.
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
