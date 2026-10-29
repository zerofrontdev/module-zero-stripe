<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Controller\Checkout;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\OrderFactory;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Stripe\Webhook as StripeWebhook;
use Zero\Checkout\Model\RedirectPayment\Webhooks;
use Zero\Stripe\Model\Checkout\Payment;

/**
 * Stripe's webhook for Stripe Checkout (zero_stripe/checkout/webhook; events
 * checkout.session.completed, .async_payment_succeeded, .async_payment_failed,
 * .expired). With a Webhook Signing Secret set, the Stripe-Signature header
 * is checked; either way the event only says which order to look at: the
 * session is asked from Stripe (OrderUpdater, now or from the queue: Zero
 * Checkout > Payment Webhooks). 200 when handled or not ours; 400 for a bad
 * signature; 503 when Stripe can't be asked right now, so it sends it again.
 */
class Webhook implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly OrderFactory $orderFactory,
        private readonly Webhooks $webhooks,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger,
        private readonly ManagerInterface $eventManager
    ) {
    }

    /**
     * @return Raw
     */
    public function execute()
    {
        $result = $this->rawFactory->create()->setContents('');
        /** @var Http $request */
        $request = $this->request;
        $payload = (string) $request->getContent();
        $event = json_decode($payload, true);
        $session = is_array($event) ? (array) ($event['data']['object'] ?? []) : [];
        $type = (string) ($event['type'] ?? '');
        if (!str_starts_with($type, 'checkout.session.')) {
            return $this->otherEvent($payload, is_array($event) ? $event : [], $session, $result);
        }
        $reference = (string) ($session['metadata']['order'] ?? $session['client_reference_id'] ?? '');
        if ($reference === '') {
            return $result->setHttpResponseCode(200);
        }
        $order = $this->orderFactory->create()->loadByIncrementId($reference);
        if (!$order->getId() || $order->getPayment()?->getMethod() !== Payment::CODE
            || $order->getPayment()->getAdditionalInformation(Payment::SESSION_ID) !== ($session['id'] ?? null)
        ) {
            return $result->setHttpResponseCode(200);
        }
        $secret = $this->secret((int) $order->getStoreId());
        if ($secret !== '') {
            try {
                StripeWebhook::constructEvent($payload, (string) $request->getHeader('Stripe-Signature'), $secret);
            } catch (\Throwable $e) {
                $this->logger->warning('Zero Stripe Checkout: webhook with a bad signature for order ' . $reference);

                return $result->setHttpResponseCode(400);
            }
        }
        try {
            $this->webhooks->handle($order, ['type' => (string) $event['type']]);
        } catch (LocalizedException) {
            return $result->setHttpResponseCode(503);
        }

        return $result->setHttpResponseCode(200);
    }

    /**
     * Any other event about one of the store's orders (a subscription's
     * invoices and changes carry the order in their metadata): checked
     * against the order's store's signing secret, then handed on as the
     * Magento event `zero_stripe_webhook` (Zero_Subscriptions listens).
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $object
     */
    private function otherEvent(string $payload, array $event, array $object, Raw $result): Raw
    {
        $reference = (string) ($object['metadata']['order']
            ?? $object['parent']['subscription_details']['metadata']['order']
            ?? $object['subscription_details']['metadata']['order']
            ?? '');
        if ($reference === '') {
            return $result->setHttpResponseCode(200);
        }
        $order = $this->orderFactory->create()->loadByIncrementId($reference);
        if (!$order->getId() || $order->getPayment()?->getMethod() !== Payment::CODE) {
            return $result->setHttpResponseCode(200);
        }
        $secret = $this->secret((int) $order->getStoreId());
        if ($secret !== '') {
            try {
                /** @var Http $request */
                $request = $this->request;
                StripeWebhook::constructEvent($payload, (string) $request->getHeader('Stripe-Signature'), $secret);
            } catch (\Throwable) {
                $this->logger->warning('Zero Stripe Checkout: webhook with a bad signature for order ' . $reference);

                return $result->setHttpResponseCode(400);
            }
        }
        try {
            $this->eventManager->dispatch('zero_stripe_webhook', ['stripe_event' => $event, 'order' => $order]);
        } catch (LocalizedException $e) {
            $this->logger->warning('Zero Stripe Checkout: ' . ($event['type'] ?? '') . ' for order ' . $reference . ': ' . $e->getMessage());

            return $result->setHttpResponseCode(503);
        }

        return $result->setHttpResponseCode(200);
    }

    private function secret(int $storeId): string
    {
        $value = trim((string) $this->scopeConfig->getValue('payment/' . Payment::CODE . '/webhook_secret', ScopeInterface::SCOPE_STORE, $storeId));

        return preg_match('/^\d+:\d+:/', $value) ? trim((string) $this->encryptor->decrypt($value)) : $value;
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Signed by Stripe instead; nothing is taken from the call.
     *
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
