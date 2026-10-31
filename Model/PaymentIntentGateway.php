<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Model;

use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;

/**
 * The Stripe PaymentIntent API calls Zero makes, behind one injectable class
 * (so capture/intent logic can be unit-tested without the network).
 */
class PaymentIntentGateway
{
    /**
     * @throws ApiErrorException
     */
    public function retrieve(string $secretKey, string $intentId): PaymentIntent
    {
        return (new StripeClient($secretKey))->paymentIntents->retrieve($intentId);
    }

    /**
     * @param array<string, mixed> $params
     * @throws ApiErrorException
     */
    public function create(string $secretKey, array $params): PaymentIntent
    {
        return (new StripeClient($secretKey))->paymentIntents->create($params);
    }

    /**
     * @param array<string, mixed> $params
     * @throws ApiErrorException
     */
    public function update(string $secretKey, string $intentId, array $params): PaymentIntent
    {
        return (new StripeClient($secretKey))->paymentIntents->update($intentId, $params);
    }

    /**
     * Captures the whole amount a manual-capture intent holds. The key makes a
     * retried capture (same order) a replay instead of a second request.
     *
     * @throws ApiErrorException
     */
    public function capture(string $secretKey, string $intentId, string $idempotencyKey): PaymentIntent
    {
        return (new StripeClient($secretKey))->paymentIntents->capture(
            $intentId,
            [],
            ['idempotency_key' => $idempotencyKey]
        );
    }

    /**
     * Releases a held (requires_capture) intent, or makes an unpaid one unusable.
     *
     * @throws ApiErrorException
     */
    public function cancel(string $secretKey, string $intentId): PaymentIntent
    {
        return (new StripeClient($secretKey))->paymentIntents->cancel($intentId);
    }

    /**
     * Refunds $amount (minor units) of a PaymentIntent's charge. The key
     * (RefundKey) is the same when Magento retries the same credit memo.
     *
     * @throws ApiErrorException
     */
    public function refund(string $secretKey, string $intentId, int $amount, string $idempotencyKey): Refund
    {
        return (new StripeClient($secretKey))->refunds->create(
            ['payment_intent' => $intentId, 'amount' => $amount],
            ['idempotency_key' => $idempotencyKey]
        );
    }
}
