<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Zero\Checkout\Model\Money\MinorUnits;

/**
 * The card PaymentIntent for a quote, sized to its grand total in the quote
 * currency, with manual capture (the card is only authorized in the browser;
 * the order captures it once saved). Created on the first call and remembered
 * on the quote's payment (stripe_payment_intent_id); on the next calls the
 * stored intent is asked from Stripe first:
 * - still to be confirmed: updated (amount, currency, quote, manual capture);
 * - held for this quote (a placement that failed and wasn't released):
 *   cancelled, and a new one made; when a saved order was placed with it
 *   (IntentOrders: a step after the save failed), it is kept for that order,
 *   logged, and a new one made;
 * - succeeded or cancelled: a new one made;
 * - processing: refused until Stripe has finished.
 * Behind the theme's zero_stripe/standard/intent and the headless
 * zeroStripeIntent mutation; Standard::authorize() rejects an intent made for
 * another quote or another amount.
 */
class QuoteIntent
{
    public const INTENT_ID = 'stripe_payment_intent_id';

    private const UPDATABLE = [
        PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
        PaymentIntent::STATUS_REQUIRES_CONFIRMATION,
        PaymentIntent::STATUS_REQUIRES_ACTION,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly PaymentIntentGateway $gateway,
        private readonly LoggerInterface $logger,
        private readonly IntentOrders $intentOrders
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function forQuote(Quote $quote): PaymentIntent
    {
        if (!$quote->getId() || (float) $quote->getGrandTotal() <= 0.0) {
            throw new LocalizedException(__('Your cart is empty.'));
        }
        $secretKey = $this->config->getSecretKey((int) $quote->getStoreId());
        if ($secretKey === '') {
            throw new LocalizedException(__('Card payment is not available right now.'));
        }

        $currency = (string) $quote->getQuoteCurrencyCode();
        $params = [
            'amount' => MinorUnits::toMinor((float) $quote->getGrandTotal(), $currency, MinorUnits::STRIPE),
            'currency' => strtolower($currency),
            'metadata' => ['quote_id' => (string) $quote->getId()],
            'capture_method' => 'manual',
        ];
        $payment = $quote->getPayment();
        $existingIntentId = (string) $payment->getAdditionalInformation(self::INTENT_ID);

        try {
            if ($existingIntentId !== '') {
                $existing = $this->gateway->retrieve($secretKey, $existingIntentId);
                $status = (string) $existing->status;
                if (in_array($status, self::UPDATABLE, true)) {
                    // capture_method too: intents made before manual capture switch on their next update.
                    return $this->gateway->update($secretKey, $existingIntentId, $params);
                }
                if ($status === PaymentIntent::STATUS_PROCESSING) {
                    throw new LocalizedException(
                        __('Your previous card payment is still being processed. Please wait a minute and try again.')
                    );
                }
                if ($status === PaymentIntent::STATUS_REQUIRES_CAPTURE
                    && (string) ($existing->metadata['quote_id'] ?? '') === (string) $quote->getId()
                ) {
                    $orderId = $this->intentOrders->orderFor((int) $quote->getId(), $existingIntentId);
                    if ($orderId !== null) {
                        // A saved order holds it (a step after the save failed and the quote stayed
                        // active): that order captures it, so it is kept and a new one is made.
                        $this->logger->warning(sprintf(
                            'Zero Stripe: quote %s holds intent %s, which order #%s was placed with; the intent is kept and a new one is made. Check that order.',
                            $quote->getId(),
                            $existingIntentId,
                            $orderId
                        ));
                    } else {
                        // Authorized, but no order was saved with it: release the hold before asking again.
                        $this->gateway->cancel($secretKey, $existingIntentId);
                    }
                }
                if ($status === PaymentIntent::STATUS_SUCCEEDED) {
                    $this->logger->warning(sprintf(
                        'Zero Stripe: quote %s holds intent %s that was already paid; a new intent is made. Check it in the Stripe dashboard.',
                        $quote->getId(),
                        $existingIntentId
                    ));
                }
            }
            // Card only, like Braintree's form — automatic_payment_methods would add tabs
            // for every wallet/BNPL method enabled in the Stripe Dashboard.
            $intent = $this->gateway->create($secretKey, $params + ['payment_method_types' => ['card']]);
        } catch (ApiErrorException $e) {
            throw new LocalizedException(__('Could not start card payment. Please try again.'), $e);
        }
        $payment->setAdditionalInformation(self::INTENT_ID, $intent->id);
        $payment->save();

        return $intent;
    }
}
