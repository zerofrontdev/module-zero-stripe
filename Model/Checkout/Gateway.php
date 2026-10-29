<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Model\Checkout;

use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;

/**
 * The Stripe Checkout calls Stripe Checkout (the hosted page) makes, behind
 * one injectable class, so the payment logic is tested without the network.
 */
class Gateway
{
    /**
     * @param array<string, mixed> $params
     * @throws ApiErrorException
     */
    public function createSession(string $secretKey, array $params): Session
    {
        return (new StripeClient($secretKey))->checkout->sessions->create($params);
    }

    /**
     * The session with its PaymentIntent expanded.
     *
     * @throws ApiErrorException
     */
    public function session(string $secretKey, string $sessionId): Session
    {
        return (new StripeClient($secretKey))->checkout->sessions->retrieve($sessionId, ['expand' => ['payment_intent']]);
    }

    /**
     * The PaymentIntent that paid an invoice (a subscription's first or a
     * renewal invoice), '' when none: invoices list their payments since API
     * version 2025-03-31.
     *
     * @throws ApiErrorException
     */
    public function invoiceIntent(string $secretKey, string $invoiceId): string
    {
        $invoice = (new StripeClient($secretKey))->invoices->retrieve($invoiceId, ['expand' => ['payments']]);
        foreach ($invoice->payments->data ?? [] as $payment) {
            $intent = $payment->payment->payment_intent ?? null;
            if ($intent) {
                return is_string($intent) ? $intent : (string) $intent->id;
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $params
     * @throws ApiErrorException
     */
    public function portalSession(string $secretKey, array $params): \Stripe\BillingPortal\Session
    {
        return (new StripeClient($secretKey))->billingPortal->sessions->create($params);
    }

    /**
     * @throws ApiErrorException
     */
    public function subscription(string $secretKey, string $subscriptionId): \Stripe\Subscription
    {
        return (new StripeClient($secretKey))->subscriptions->retrieve($subscriptionId);
    }

    /**
     * Cancels at the end of the paid period (true) or at once (false).
     *
     * @throws ApiErrorException
     */
    public function cancelSubscription(string $secretKey, string $subscriptionId, bool $atPeriodEnd): \Stripe\Subscription
    {
        $client = new StripeClient($secretKey);
        return $atPeriodEnd
            ? $client->subscriptions->update($subscriptionId, ['cancel_at_period_end' => true])
            : $client->subscriptions->cancel($subscriptionId);
    }

    /**
     * @throws ApiErrorException
     */
    public function expire(string $secretKey, string $sessionId): Session
    {
        return (new StripeClient($secretKey))->checkout->sessions->expire($sessionId);
    }

    /**
     * @throws ApiErrorException
     */
    public function capture(string $secretKey, string $intentId, int $amount): PaymentIntent
    {
        return (new StripeClient($secretKey))->paymentIntents->capture($intentId, ['amount_to_capture' => $amount]);
    }

    /**
     * @throws ApiErrorException
     */
    public function cancel(string $secretKey, string $intentId): PaymentIntent
    {
        return (new StripeClient($secretKey))->paymentIntents->cancel($intentId);
    }

    /**
     * @throws ApiErrorException
     */
    public function refund(string $secretKey, string $intentId, int $amount): Refund
    {
        return (new StripeClient($secretKey))->refunds->create(['payment_intent' => $intentId, 'amount' => $amount]);
    }
}
