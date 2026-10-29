<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;

/**
 * The card PaymentIntent for a quote, sized to its grand total: created on the
 * first call and remembered on the quote's payment (stripe_payment_intent_id),
 * updated on the next ones. Behind the theme's zero_stripe/standard/intent and
 * the headless zeroStripeIntent mutation; capture() rejects an intent made for
 * another quote or another amount.
 */
class QuoteIntent
{
    public const INTENT_ID = 'stripe_payment_intent_id';

    public function __construct(
        private readonly Config $config,
        private readonly PaymentIntentGateway $gateway
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

        $amount = (int) round(((float) $quote->getGrandTotal()) * 100);
        $currency = strtolower((string) $quote->getQuoteCurrencyCode());
        $payment = $quote->getPayment();
        $existingIntentId = (string) $payment->getAdditionalInformation(self::INTENT_ID);

        try {
            if ($existingIntentId !== '') {
                // metadata.quote_id on update too, so older intents get bound to their quote
                // (capture() rejects intents made for another quote).
                return $this->gateway->update($secretKey, $existingIntentId, [
                    'amount' => $amount,
                    'currency' => $currency,
                    'metadata' => ['quote_id' => (string) $quote->getId()],
                ]);
            }
            // Card only, like Braintree's form — automatic_payment_methods would add tabs
            // for every wallet/BNPL method enabled in the Stripe Dashboard.
            $intent = $this->gateway->create($secretKey, [
                'amount' => $amount,
                'currency' => $currency,
                'payment_method_types' => ['card'],
                'metadata' => ['quote_id' => (string) $quote->getId()],
            ]);
        } catch (ApiErrorException $e) {
            throw new LocalizedException(__('Could not start card payment. Please try again.'), $e);
        }
        $payment->setAdditionalInformation(self::INTENT_ID, $intent->id);
        $payment->save();

        return $intent;
    }
}
