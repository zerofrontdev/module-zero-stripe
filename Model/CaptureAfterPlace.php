<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Model;

use Magento\Framework\Notification\NotifierInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Model\Order;
use Zero\Checkout\Api\AfterPlaceOrderInterface;

/**
 * Right after a Stripe card order is saved (the theme's Place Order and
 * GraphQL's placeOrder): invoices it with Capture Online, so Standard::capture()
 * takes the amount the card was authorized for.
 *
 * If the capture fails the order stays as placed (processing, authorized, no
 * invoice): the shop gets an admin notice and, from Place Order, an order
 * comment, and captures it with Invoice > Capture Online within about 7 days
 * (the card hold) or cancels it. The exception goes on to the caller, which
 * logs it; the customer still sees the success page.
 */
class CaptureAfterPlace implements AfterPlaceOrderInterface
{
    public function __construct(
        private readonly InvoiceOrderInterface $invoiceOrder,
        private readonly NotifierInterface $notifier
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(OrderInterface $order, array $request): void
    {
        /** @var Order $order */
        $payment = $order->getPayment();
        if ($payment === null || $payment->getMethod() !== 'zero_stripe' || !$order->canInvoice()) {
            return;
        }
        // Only an open authorization: with a closed one Magento would not capture online.
        $auth = $payment->getAuthorizationTransaction();
        if (!$auth || $auth->getIsClosed()) {
            return;
        }
        try {
            $this->invoiceOrder->execute((int) $order->getEntityId(), true, [], false);
        } catch (\Throwable $e) {
            $this->notifier->addMajor(
                (string) __('Card payment of order #%1 was not captured', $order->getIncrementId()),
                (string) __(
                    'The card is authorized but the capture failed: %1 (details in var/log/exception.log). Capture it from the order (Invoice, Capture Online) within 7 days, or cancel the order to release the hold.',
                    $e->getMessage()
                )
            );
            throw $e;
        }
    }
}
