<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Zero\Stripe\Model\QuoteIntent;
use Zero\Stripe\Model\ReleaseIntent;

/**
 * Placing a Stripe card order failed after the card was authorized: releases
 * the hold (ReleaseIntent). Listens to Zero Checkout's
 * `zero_checkout_place_order_failed` (quote, request, exception, order_id) and
 * to Magento's `sales_model_service_quote_submit_failure` (order, quote), which
 * also covers GraphQL's placeOrder. Nothing is released when the order was
 * saved before the failure (order_id set): CaptureAfterPlace or the admin
 * captures that one.
 */
class ReleaseFailedPlacement implements ObserverInterface
{
    public function __construct(
        private readonly ReleaseIntent $releaseIntent
    ) {
    }

    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getData('quote');
        if (!$quote instanceof Quote || !$quote->getId() || $observer->getEvent()->getData('order_id')) {
            return;
        }
        $payment = $quote->getPayment();
        $order = $observer->getEvent()->getData('order');
        $method = (string) $payment->getMethod();
        $intentId = (string) $payment->getAdditionalInformation(QuoteIntent::INTENT_ID);
        if ($intentId === '' && $order && $order->getPayment()) {
            $method = $method !== '' ? $method : (string) $order->getPayment()->getMethod();
            $intentId = (string) $order->getPayment()->getAdditionalInformation(QuoteIntent::INTENT_ID);
        }
        if ($method !== 'zero_stripe' || $intentId === '') {
            return;
        }
        $this->releaseIntent->release((int) $quote->getId(), $intentId, (int) $quote->getStoreId());
    }
}
