<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Controller\Checkout;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Sales\Model\Order;
use Zero\Checkout\Model\RedirectPayment\ReturnFlow;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Stripe\Model\Checkout\OrderUpdater;
use Zero\Stripe\Model\Checkout\Payment;

/**
 * Where Stripe Checkout sends the customer back (?order=<increment id>, and
 * the session id after paying). Under the order's lock (ReturnFlow), asks
 * Stripe how the payment went (OrderUpdater). A session still open means the
 * customer came back without paying: it is expired and the order cancelled
 * (OrderUpdater::abandon(); if Stripe won't expire it, its new answer counts,
 * and a session still open counts as unconfirmed, so the cart isn't restored).
 * Then ReturnResult: the success page, or the checkout page with the cart
 * restored.
 */
class Back implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ReturnResult $returnResult,
        private readonly OrderUpdater $orderUpdater,
        private readonly ReturnFlow $returnFlow
    ) {
    }

    /**
     * @return Redirect
     */
    public function execute()
    {
        $orderParam = $this->request->getParam('order');
        $order = $this->returnResult->lastOrder(Payment::CODE, is_scalar($orderParam) ? (string) $orderParam : '');
        if (!$order) {
            return $this->returnResult->elsewhere();
        }
        // abandon() runs inside the update rather than as ReturnFlow's onNotStarted:
        // when the session can't be expired, the state it reads again must win.
        $state = $this->returnFlow->run($order, function (Order $fresh): string {
            $state = $this->orderUpdater->update($fresh);

            return $state === ReturnResult::NOT_STARTED ? $this->orderUpdater->abandon($fresh) : $state;
        });

        return $this->returnResult->redirect($state, 'Stripe');
    }
}
