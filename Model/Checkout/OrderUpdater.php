<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Model\Checkout;

use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Zero\Checkout\Model\Money\MinorUnits;
use Zero\Checkout\Model\RedirectPayment\LatePaymentInterface;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\OrderUpdaterInterface;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Checkout\Model\RedirectPayment\WebhookHandlerInterface;
use Zero\Stripe\Model\Config;

/**
 * Brings an order in line with its Checkout Session, asked from Stripe (the
 * webhook and the browser only say which order to look at):
 * - the PaymentIntent succeeded, for the order's amount and currency: paid;
 * - it awaits capture (Hold Until Invoiced): authorized;
 * - another amount or currency: the order keeps waiting, flagged;
 * - processing (bank debits and other delayed methods): pending, the webhook
 *   brings the result;
 * - the session expired, or its PaymentIntent was cancelled: cancelled;
 * - the session still open (the customer came back without paying): not started,
 *   nothing changed (Back then calls abandon()).
 * Running it twice changes nothing the second time; it never cancels an order
 * whose session is still open, so the recheck cron (Updaters) may run it.
 * For a cancelled order, latePayment() only reads what Stripe holds.
 */
class OrderUpdater implements WebhookHandlerInterface, OrderUpdaterInterface, LatePaymentInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Gateway $gateway,
        private readonly OrderPayment $orderPayment,
        private readonly ManagerInterface $eventManager
    ) {
    }

    /**
     * @return string A ReturnResult state
     * @throws LocalizedException When Stripe doesn't answer
     */
    public function update(Order $order, array $params = []): string
    {
        $sessionId = (string) $order->getPayment()?->getAdditionalInformation(Payment::SESSION_ID);
        if ($order->getPayment()?->getMethod() !== Payment::CODE || $sessionId === '') {
            return ReturnResult::PENDING;
        }
        $settled = $this->orderPayment->settled($order);
        if ($settled !== null) {
            return $settled;
        }
        try {
            $session = $this->gateway->session($this->config->getSecretKey((int) $order->getStoreId()), $sessionId);
        } catch (ApiErrorException $e) {
            throw new LocalizedException(__('Stripe is not answering. Please try again in a minute.'), $e);
        }

        return $this->apply($order, $session);
    }

    /**
     * @inheritDoc
     */
    public function webhook(Order $order, array $data): void
    {
        $this->update($order);
    }

    /**
     * @inheritDoc
     */
    public function latePayment(Order $order): ?array
    {
        $sessionId = (string) $order->getPayment()?->getAdditionalInformation(Payment::SESSION_ID);
        if ($sessionId === '') {
            return null;
        }
        try {
            $session = $this->gateway->session($this->config->getSecretKey((int) $order->getStoreId()), $sessionId);
        } catch (ApiErrorException $e) {
            throw new LocalizedException(__('Stripe is not answering. Please try again in a minute.'), $e);
        }
        if ((string) $session->status !== 'complete') {
            return null;
        }
        if ((string) $session->mode === 'subscription') {
            if ((string) $session->payment_status !== 'paid') {
                return null;
            }
            $id = is_object($session->subscription) ? (string) $session->subscription->id : (string) $session->subscription;
        } else {
            $intent = $session->payment_intent;
            if (!is_object($intent) || !in_array((string) $intent->status, ['succeeded', 'requires_capture'], true)) {
                return null;
            }
            $id = (string) $intent->id;
        }
        $currency = strtoupper((string) $session->currency);

        return [
            'id' => $id !== '' ? $id : $sessionId,
            'amount' => MinorUnits::format(
                MinorUnits::fromMinor((int) $session->amount_total, $currency, MinorUnits::STRIPE),
                $currency,
                MinorUnits::STRIPE
            ),
            'currency' => $currency,
        ];
    }

    /**
     * The customer came back from an open session without paying: the
     * session is expired, so it can't be paid any more, then the order is
     * cancelled. When Stripe won't expire it (it was completed meanwhile, or
     * Stripe is down), nothing is cancelled and the session is asked again:
     * that answer is the result, except that a session still open counts as
     * UNCONFIRMED (not NOT_STARTED): it can still be paid, so the cart is not
     * restored while the order waits for it.
     *
     * @return string A ReturnResult state
     * @throws LocalizedException When Stripe doesn't answer the second time
     */
    public function abandon(Order $order): string
    {
        $sessionId = (string) $order->getPayment()?->getAdditionalInformation(Payment::SESSION_ID);
        try {
            $this->gateway->expire($this->config->getSecretKey((int) $order->getStoreId()), $sessionId);
        } catch (ApiErrorException) {
            $state = $this->update($order);

            return $state === ReturnResult::NOT_STARTED ? ReturnResult::UNCONFIRMED : $state;
        }
        $this->orderPayment->cancel($order, (string) __('The customer came back from Stripe without paying.'));

        return ReturnResult::NOT_STARTED;
    }

    /**
     * The decision for a session Stripe gave back.
     */
    public function apply(Order $order, Session $session): string
    {
        if ((string) $session->mode === 'subscription') {
            return $this->applySubscription($order, $session);
        }
        $intent = $session->payment_intent;
        $intentStatus = is_object($intent) ? (string) $intent->status : '';
        if ($session->status === 'expired' || $intentStatus === 'canceled') {
            $this->orderPayment->cancel($order, (string) __('The Stripe payment was not completed (the checkout expired or was cancelled).'));

            return ReturnResult::FAILED;
        }
        if ($session->status !== 'complete' || !is_object($intent)) {
            return ReturnResult::NOT_STARTED;
        }
        if (!in_array($intentStatus, ['succeeded', 'requires_capture'], true)) {
            return ReturnResult::PENDING;
        }
        $currency = (string) $order->getOrderCurrencyCode();
        $expected = Payment::minor((float) $order->getGrandTotal(), $currency);
        if ((int) $session->amount_total !== $expected || strtoupper((string) $session->currency) !== strtoupper($currency)) {
            $this->orderPayment->flag(
                $order,
                (string) __('Stripe reports a payment of a different amount; check it in the Stripe dashboard.'),
                sprintf('Stripe Checkout paid %d %s, expected %d %s', (int) $session->amount_total, (string) $session->currency, $expected, $currency)
            );

            return ReturnResult::PENDING;
        }
        $order->getPayment()->setAdditionalInformation(Payment::INTENT_ID, (string) $intent->id);
        if ($intentStatus === 'requires_capture') {
            $this->orderPayment->authorize($order, (string) $intent->id);
        } else {
            $this->orderPayment->capture($order, (string) $intent->id);
        }

        return ReturnResult::PAID;
    }

    /**
     * A subscription session: paid when its first invoice is; the order is
     * captured on that invoice's PaymentIntent (refunds go to it), and
     * `zero_stripe_checkout_subscription_paid` tells Zero_Subscriptions.
     */
    private function applySubscription(Order $order, Session $session): string
    {
        if ($session->status === 'expired') {
            $this->orderPayment->cancel($order, (string) __('The Stripe payment was not completed (the checkout expired or was cancelled).'));

            return ReturnResult::FAILED;
        }
        if ($session->status !== 'complete') {
            return ReturnResult::NOT_STARTED;
        }
        if ((string) $session->payment_status !== 'paid') {
            return ReturnResult::PENDING;
        }
        $currency = (string) $order->getOrderCurrencyCode();
        $expected = Payment::minor((float) $order->getGrandTotal(), $currency);
        if ((int) $session->amount_total !== $expected || strtoupper((string) $session->currency) !== strtoupper($currency)) {
            $this->orderPayment->flag(
                $order,
                (string) __('Stripe reports a payment of a different amount; check it in the Stripe dashboard.'),
                sprintf('Stripe Checkout paid %d %s, expected %d %s', (int) $session->amount_total, (string) $session->currency, $expected, $currency)
            );

            return ReturnResult::PENDING;
        }
        $invoiceId = is_object($session->invoice) ? (string) $session->invoice->id : (string) $session->invoice;
        $subscriptionId = is_object($session->subscription) ? (string) $session->subscription->id : (string) $session->subscription;
        try {
            $intentId = $invoiceId !== '' ? $this->gateway->invoiceIntent($this->config->getSecretKey((int) $order->getStoreId()), $invoiceId) : '';
        } catch (ApiErrorException $e) {
            throw new LocalizedException(__('Stripe is not answering. Please try again in a minute.'), $e);
        }
        $payment = $order->getPayment();
        $payment->setAdditionalInformation(Payment::INTENT_ID, $intentId);
        $payment->setAdditionalInformation('stripe_subscription_id', $subscriptionId);
        $payment->setAdditionalInformation('stripe_customer_id', is_object($session->customer) ? (string) $session->customer->id : (string) $session->customer);
        $this->orderPayment->capture($order, $intentId !== '' ? $intentId : $invoiceId);
        $this->eventManager->dispatch('zero_stripe_checkout_subscription_paid', ['order' => $order, 'session' => $session]);

        return ReturnResult::PAID;
    }
}
