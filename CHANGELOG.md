# Changelog: zerofront/module-zero-stripe

## 1.3.6

- **Credit Card (Stripe)** now authorises the card in the browser and captures it after the order is saved (it captured before the order existed). If placing the order fails, the hold on the card is released.
- The PaymentIntent is checked on the server before the order is placed: made for this cart, for the order's total, in the order's currency.
- If the capture fails, the order stays processing and authorised without an invoice, with an admin notice and an order comment: capture it from the order (Invoice > Capture Online) within about 7 days, or cancel it to release the hold.
- Without a webhook secret every Stripe webhook is refused (400, logged "webhook secret not set"): Stripe Checkout orders are then updated only when the customer comes back, or by Zero Checkout's recheck cron when it is on. Set the secret.
- Stripe Checkout settles through Zero Checkout's new flow (one lock per order, late payments flagged) and can be asked by the recheck cron.
- Refunds carry an idempotency key from the credit memo, so a retried credit memo doesn't refund twice; amounts use Stripe's minor units per currency.
- Going back to 1.3.5: first capture or cancel every processing card order that has no invoice; the old code can't capture these authorisations.

Earlier releases (1.3.5 and before): see the Git tags.
