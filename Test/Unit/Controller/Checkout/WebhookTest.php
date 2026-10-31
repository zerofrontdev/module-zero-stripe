<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Test\Unit\Controller\Checkout;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPaymentInfo;
use Magento\Sales\Model\OrderFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Checkout\Model\RedirectPayment\Webhooks;
use Zero\Stripe\Controller\Checkout\Webhook;
use Zero\Stripe\Model\Checkout\Payment;

/**
 * A3-4: without a signing secret every webhook is refused (400) before the
 * order is touched, on both the checkout.session.* path and the
 * other-events path; a bad signature is 400, a held lock 503.
 */
class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_1';

    private Webhooks&MockObject $webhooks;
    private ManagerInterface&MockObject $eventManager;
    private LoggerInterface&MockObject $logger;
    private string $secret = self::SECRET;
    private string $payload = '';
    private string $signature = '';
    private ?int $code = null;

    protected function setUp(): void
    {
        $this->webhooks = $this->createMock(Webhooks::class);
        $this->eventManager = $this->createMock(ManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testEmptySecretRefusesSessionEvents(): void
    {
        $this->secret = '';
        $this->sessionEvent(true);
        $this->webhooks->expects($this->never())->method('handle');
        $this->eventManager->expects($this->never())->method('dispatch');
        $this->logger->expects($this->once())->method('warning')
            ->with('Zero Stripe Checkout: webhook for order 000000123 refused: webhook secret not set');

        $this->controller()->execute();

        $this->assertSame(400, $this->code);
    }

    public function testEmptySecretRefusesOtherEvents(): void
    {
        $this->secret = '';
        $this->invoiceEvent(true);
        $this->webhooks->expects($this->never())->method('handle');
        $this->eventManager->expects($this->never())->method('dispatch');
        $this->logger->expects($this->once())->method('warning')
            ->with('Zero Stripe Checkout: webhook for order 000000123 refused: webhook secret not set');

        $this->controller()->execute();

        $this->assertSame(400, $this->code);
    }

    public function testBadSignature400(): void
    {
        $this->sessionEvent(false);
        $this->webhooks->expects($this->never())->method('handle');
        $this->eventManager->expects($this->never())->method('dispatch');
        $this->logger->expects($this->once())->method('warning')
            ->with('Zero Stripe Checkout: webhook with a bad signature for order 000000123');

        $this->controller()->execute();

        $this->assertSame(400, $this->code);
    }

    public function testSignedSessionEventIsHandled(): void
    {
        $this->sessionEvent(true);
        $this->webhooks->expects($this->once())->method('handle')
            ->with($this->isInstanceOf(Order::class), ['type' => 'checkout.session.completed']);
        $this->logger->expects($this->never())->method('warning');
        $this->eventManager->expects($this->never())->method('dispatch');

        $this->controller()->execute();

        $this->assertSame(200, $this->code);
    }

    public function testLockHeld503(): void
    {
        $this->sessionEvent(true);
        $this->webhooks->expects($this->once())->method('handle')
            ->willThrowException(new LocalizedException(__('The order is being updated.')));
        $this->eventManager->expects($this->never())->method('dispatch');
        $this->logger->expects($this->never())->method('warning');

        $this->controller()->execute();

        $this->assertSame(503, $this->code);
    }

    public function testSignedOtherEventIsDispatched(): void
    {
        $this->invoiceEvent(true);
        $this->eventManager->expects($this->once())->method('dispatch')->with(
            'zero_stripe_webhook',
            $this->callback(fn (array $data) => $data['stripe_event']['type'] === 'invoice.paid'
                && $data['order'] instanceof Order)
        );
        $this->webhooks->expects($this->never())->method('handle');
        $this->logger->expects($this->never())->method('warning');

        $this->controller()->execute();

        $this->assertSame(200, $this->code);
    }

    private function sessionEvent(bool $signed): void
    {
        $this->sign([
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test_1', 'metadata' => ['order' => '000000123']]],
        ], $signed);
    }

    private function invoiceEvent(bool $signed): void
    {
        $this->sign([
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_1',
                'parent' => ['subscription_details' => ['metadata' => ['order' => '000000123']]],
            ]],
        ], $signed);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function sign(array $event, bool $valid): void
    {
        $this->payload = (string) json_encode($event);
        $time = time();
        $mac = hash_hmac('sha256', $time . '.' . $this->payload, $valid ? self::SECRET : 'whsec_other');
        $this->signature = 't=' . $time . ',v1=' . $mac;
    }

    private function controller(): Webhook
    {
        $request = $this->createStub(Http::class);
        $request->method('getContent')->willReturnCallback(fn () => $this->payload);
        $request->method('getHeader')->willReturnCallback(
            fn (string $name) => $name === 'Stripe-Signature' ? $this->signature : false
        );

        $raw = $this->createStub(Raw::class);
        $raw->method('setContents')->willReturnSelf();
        $raw->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($raw) {
            $this->code = $code;
            return $raw;
        });
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        $payment = $this->createStub(OrderPaymentInfo::class);
        $payment->method('getMethod')->willReturn(Payment::CODE);
        $payment->method('getAdditionalInformation')->willReturnMap([[Payment::SESSION_ID, 'cs_test_1']]);
        $order = $this->createStub(Order::class);
        $order->method('loadByIncrementId')->willReturnSelf();
        $order->method('getId')->willReturn(9);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getPayment')->willReturn($payment);
        $orderFactory = $this->createStub(OrderFactory::class);
        $orderFactory->method('create')->willReturn($order);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path) => $path === 'payment/zero_stripe_checkout/webhook_secret' ? $this->secret : null
        );

        return new Webhook(
            $request,
            $rawFactory,
            $orderFactory,
            $this->webhooks,
            $scopeConfig,
            $this->createStub(EncryptorInterface::class),
            $this->logger,
            $this->eventManager
        );
    }
}
