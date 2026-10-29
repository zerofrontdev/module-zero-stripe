<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Test\Unit\Model\Checkout;

use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPaymentInfo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stripe\Checkout\Session;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Stripe\Model\Checkout\Gateway;
use Zero\Stripe\Model\Checkout\OrderUpdater;
use Zero\Stripe\Model\Checkout\Payment;
use Zero\Stripe\Model\Config;

class OrderUpdaterTest extends TestCase
{
    private OrderPayment&MockObject $orderPayment;
    private OrderUpdater $updater;
    private array $info = [];

    protected function setUp(): void
    {
        $this->orderPayment = $this->createMock(OrderPayment::class);
        $this->updater = new OrderUpdater(
            $this->createMock(Config::class),
            $this->createMock(Gateway::class),
            $this->orderPayment,
            $this->createMock(EventManager::class)
        );
        $this->info = [Payment::SESSION_ID => 'cs_test_1'];
    }

    private function order(): Order&MockObject
    {
        $payment = $this->createMock(OrderPaymentInfo::class);
        $payment->method('getMethod')->willReturn(Payment::CODE);
        $payment->method('getAdditionalInformation')->willReturnCallback(fn ($key) => $this->info[$key] ?? null);
        $payment->method('setAdditionalInformation')->willReturnCallback(function ($key, $value) use ($payment) {
            $this->info[$key] = $value;

            return $payment;
        });
        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getGrandTotal')->willReturn(129.99);
        $order->method('getOrderCurrencyCode')->willReturn('USD');

        return $order;
    }

    private static function session(string $status, ?string $intentStatus, int $amount = 12999, string $currency = 'usd'): Session
    {
        return Session::constructFrom([
            'id' => 'cs_test_1',
            'status' => $status,
            'amount_total' => $amount,
            'currency' => $currency,
            'payment_intent' => $intentStatus === null ? null : ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => $intentStatus],
        ]);
    }

    public function testASucceededPaymentForTheOrdersTotalIsCaptured(): void
    {
        $order = $this->order();
        $this->orderPayment->expects($this->once())->method('capture')->with($order, 'pi_1');
        $this->assertSame(ReturnResult::PAID, $this->updater->apply($order, self::session('complete', 'succeeded')));
        $this->assertSame('pi_1', $this->info[Payment::INTENT_ID]);
    }

    public function testAHeldPaymentIsAuthorized(): void
    {
        $order = $this->order();
        $this->orderPayment->expects($this->once())->method('authorize')->with($order, 'pi_1');
        $this->orderPayment->expects($this->never())->method('capture');
        $this->assertSame(ReturnResult::PAID, $this->updater->apply($order, self::session('complete', 'requires_capture')));
    }

    public function testAnotherAmountIsFlagged(): void
    {
        $order = $this->order();
        $this->orderPayment->expects($this->never())->method('capture');
        $this->orderPayment->expects($this->once())->method('flag');
        $this->assertSame(ReturnResult::PENDING, $this->updater->apply($order, self::session('complete', 'succeeded', 100)));
    }

    public function testADelayedMethodIsPending(): void
    {
        $this->orderPayment->expects($this->never())->method('capture');
        $this->assertSame(ReturnResult::PENDING, $this->updater->apply($this->order(), self::session('complete', 'processing')));
    }

    public function testAnOpenSessionWasNotStarted(): void
    {
        $this->orderPayment->expects($this->never())->method('cancel');
        $this->assertSame(ReturnResult::NOT_STARTED, $this->updater->apply($this->order(), self::session('open', null)));
    }

    public function testAnExpiredSessionCancels(): void
    {
        $order = $this->order();
        $this->orderPayment->expects($this->once())->method('cancel')->with($order);
        $this->assertSame(ReturnResult::FAILED, $this->updater->apply($order, self::session('expired', null)));
    }

    public function testAmountsAreInTheCurrencysSmallestUnit(): void
    {
        $this->assertSame(12999, Payment::minor(129.99, 'USD'));
        $this->assertSame(1500, Payment::minor(1500, 'JPY'));
        $this->assertSame(1, Payment::minor(0.005, 'EUR'));
    }
}
