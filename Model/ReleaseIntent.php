<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Model;

use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;

/**
 * Cancels a card authorization that no order was saved with (the browser
 * confirmed the intent, then placing the order failed), so the customer's
 * card isn't held for days. Only an intent that is held (requires_capture)
 * and was made for this quote is touched, and only while no saved order was
 * placed with that intent (IntentOrders; Magento also reports a failure after
 * the order was saved, when a later step throws: that order keeps its
 * authorization). Another order of the same quote doesn't stop it: a cart
 * restored after a cancelled redirect payment keeps its quote id. Stripe errors
 * are logged, never thrown: the hold expires on its own, and QuoteIntent
 * cancels it on the next try.
 */
class ReleaseIntent
{
    /** @var array<string, true> intents released in this request */
    private array $released = [];

    public function __construct(
        private readonly Config $config,
        private readonly PaymentIntentGateway $gateway,
        private readonly IntentOrders $intentOrders,
        private readonly LoggerInterface $logger
    ) {
    }

    public function release(int $quoteId, string $intentId, int $storeId): void
    {
        if ($quoteId <= 0 || $intentId === '' || isset($this->released[$intentId])) {
            return;
        }
        $secretKey = $this->config->getSecretKey($storeId);
        if ($secretKey === '' || $this->intentOrders->orderFor($quoteId, $intentId) !== null) {
            return;
        }
        try {
            $intent = $this->gateway->retrieve($secretKey, $intentId);
            if ($intent->status !== PaymentIntent::STATUS_REQUIRES_CAPTURE
                || (string) ($intent->metadata['quote_id'] ?? '') !== (string) $quoteId
            ) {
                return;
            }
            $this->gateway->cancel($secretKey, $intentId);
            $this->released[$intentId] = true;
        } catch (ApiErrorException $e) {
            $this->logger->warning(sprintf(
                'Zero Stripe: could not release intent %s of quote %d: %s',
                $intentId,
                $quoteId,
                $e->getMessage()
            ));
        }
    }
}
