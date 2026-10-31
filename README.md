# Zero_Stripe

Stripe for Zero Checkout, two methods:

- **Credit Card (Stripe)** `zero_stripe`: Stripe's Payment Element in the
  checkout, a manual-capture PaymentIntent sized to the cart in its currency
  (`Model\QuoteIntent`, `Controller\Standard\Intent`), authorized in the
  browser and captured once the order is saved (`Model\Standard`,
  `Model\CaptureAfterPlace`).
- **Pay Later, Wallets and More (Stripe)** `zero_stripe_checkout`: Stripe
  Checkout, the hosted page with every method enabled in the Stripe
  Dashboard; the order waits in pending payment until the customer is back
  or the webhook arrives (`Model\Checkout\*`, `Controller\Checkout\*`).

Settings: Stores > Configuration > Sales > Payment Methods > Stripe. Keys per
mode (test/live), an optional hold for Stripe Checkout (authorize now,
capture on invoice) and the webhook secret for Stripe Checkout. Without a
webhook secret every webhook call is refused (400, logged as "webhook secret
not set"); orders are then updated only when the customer comes back (and by
Zero Checkout's recheck cron, when it is on). Zero_Subscriptions' renewal
orders (`invoice.paid`) come only from the webhook: with no secret, none is
made.

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

## Card payments: manual capture

1. `QuoteIntent` makes the quote's PaymentIntent with `capture_method=manual`,
   for the quote's grand total in the quote currency (`MinorUnits::STRIPE`).
   A stored intent is asked first: one still to be confirmed is updated; one
   held for this quote (a placement that failed) is cancelled and replaced,
   unless a saved order was placed with it (`IntentOrders`: a step after the
   save failed and the quote stayed active), then it is kept for that order,
   a warning logged and a new one made; a succeeded or cancelled one is
   replaced; a processing one is refused until Stripe has finished.
2. The browser confirms it: the card is authorized (`requires_capture`).
   If the server hands back another intent, the card form is rebuilt and the
   customer re-enters the card.
3. Placing the order, `Standard::authorize()` checks the intent: held (or
   succeeded, for an intent confirmed before manual capture), made for this
   quote, for the order's grand total in the order currency.
4. Once the order is saved, `CaptureAfterPlace` (after Placeorder's
   `afterPlaceOrder`) invoices it with Capture Online: `Standard::capture()`
   captures the intent with the idempotency key `zero-capture-<intent id>`.
5. If placing fails after the card was authorized, `ReleaseFailedPlacement`
   (on `zero_checkout_place_order_failed` and
   `sales_model_service_quote_submit_failure`) cancels the held intent,
   unless a saved order was placed with that intent (`IntentOrders` looks at
   the payment's last transaction and the payment transactions, not just any
   order of the quote: a restored cart keeps its quote id).
6. If the capture fails, the order stays processing, authorized, without an
   invoice: an admin notice and an order comment say so; Magento's invoicing
   hides Stripe's reason behind a generic message, so look in
   `var/log/exception.log` (`Zero Stripe: capture of intent … refused`). Only
   an open authorization is captured, and the method is a gateway
   (`isGateway()`), so an invoice is never marked paid offline instead of a
   failed online capture. Capture it from the
   order (Invoice > Capture Online) within about 7 days, the card hold, or
   cancel the order, which releases the hold (`void()`/`cancel()`).

Refunds go to the PaymentIntent in the order currency, with an idempotency
key from `RefundKey`, so a retried credit memo doesn't refund twice (Stripe
replays the first answer for 24 hours, a failed one too). The same holds for
Stripe Checkout refunds.

**Rollback.** Before reverting to the old auto-capture code, capture
(Invoice > Capture Online) or cancel every processing `zero_stripe` order
that has no invoice: the old `capture()` accepts only succeeded intents and
would refuse those authorizations, which expire after about 7 days.

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

<a href="https://vilmate.com"><picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo-dark.svg">
  <img src="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo.svg" alt="Vilmate" height="34" align="right">
</picture></a>

Zero is made by [Fixstacks](https://fixstacks.com) in partnership with [Vilmate](https://vilmate.com), a software development company.
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
