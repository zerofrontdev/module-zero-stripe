<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Test\Unit\Model\Checkout;

use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPaymentInfo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Stripe\Checkout\Session;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Stripe\Model\Checkout\Gateway;
use Zero\Stripe\Model\Checkout\OrderUpdater;
use Zero\Stripe\Model\Checkout\Payment;
use Zero\Stripe\Model\Config;

/**
 * The Stripe Checkout session decides the order: paid, authorized, flagged,
 * pending, not started or cancelled; latePayment() only reads what Stripe
 * holds for a cancelled order; abandon() expires the session before it
 * cancels, and when Stripe won't expire it the session's new answer wins
 * (a session still open counts as UNCONFIRMED, F5).
 */
class OrderUpdaterTest extends TestCase
{
    private OrderPayment&MockObject $orderPayment;
    private Gateway&Stub $gateway;
    private OrderUpdater $updater;
    private array $info = [];

    protected function setUp(): void
    {
        $this->orderPayment = $this->createMock(OrderPayment::class);
        $this->gateway = $this->createStub(Gateway::class);
        $this->updater = $this->updater($this->gateway);
        $this->info = [Payment::SESSION_ID => 'cs_test_1'];
    }

    private function updater(Gateway $gateway): OrderUpdater
    {
        $config = $this->createStub(Config::class);
        $config->method('getSecretKey')->willReturn('sk_test_1');

        return new OrderUpdater($config, $gateway, $this->orderPayment, $this->createStub(EventManager::class));
    }

    private function order(): Order&Stub
    {
        $payment = $this->createStub(OrderPaymentInfo::class);
        $payment->method('getMethod')->willReturn(Payment::CODE);
        $payment->method('getAdditionalInformation')->willReturnCallback(fn ($key) => $this->info[$key] ?? null);
        $payment->method('setAdditionalInformation')->willReturnCallback(function ($key, $value) use ($payment) {
            $this->info[$key] = $value;

            return $payment;
        });
        $order = $this->createStub(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getGrandTotal')->willReturn(129.99);
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->method('getStoreId')->willReturn(1);

        return $order;
    }

    private static function session(string $status, ?string $intentStatus, int $amount = 12999, string $currency = 'usd'): Session
    {
        return Session::constructFrom([
            'id' => 'cs_test_1',
            'status' => $status,
            'amount_total' => $amount,
            'currency' => $currency,
            'mode' => 'payment',
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

    public function testLatePaymentCompletedSession(): void
    {
        $this->gateway->method('session')->willReturn(self::session('complete', 'succeeded'));
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertSame(
            ['id' => 'pi_1', 'amount' => '129.99', 'currency' => 'USD'],
            $this->updater->latePayment($this->order())
        );
        $this->assertArrayNotHasKey(Payment::INTENT_ID, $this->info, 'latePayment() must not write to the order');
    }

    public function testLatePaymentHeldIntentCounts(): void
    {
        $this->gateway->method('session')->willReturn(self::session('complete', 'requires_capture', 1500, 'jpy'));
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertSame(
            ['id' => 'pi_1', 'amount' => '1500', 'currency' => 'JPY'],
            $this->updater->latePayment($this->order())
        );
    }

    public function testLatePaymentSubscriptionPaid(): void
    {
        $this->gateway->method('session')->willReturn(Session::constructFrom([
            'id' => 'cs_test_1',
            'status' => 'complete',
            'mode' => 'subscription',
            'payment_status' => 'paid',
            'subscription' => 'sub_1',
            'amount_total' => 2000,
            'currency' => 'eur',
        ]));
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertSame(
            ['id' => 'sub_1', 'amount' => '20.00', 'currency' => 'EUR'],
            $this->updater->latePayment($this->order())
        );
    }

    public function testLatePaymentSubscriptionNotPaidIsNull(): void
    {
        $this->gateway->method('session')->willReturn(Session::constructFrom([
            'id' => 'cs_test_1',
            'status' => 'complete',
            'mode' => 'subscription',
            'payment_status' => 'unpaid',
        ]));
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertNull($this->updater->latePayment($this->order()));
    }

    public function testLatePaymentNullForOpenOrUnpaidSessions(): void
    {
        $gateway = $this->createStub(Gateway::class);
        $gateway->method('session')->willReturnOnConsecutiveCalls(
            self::session('open', null),
            self::session('expired', null),
            self::session('complete', 'processing'),
            self::session('complete', 'canceled')
        );
        $updater = $this->updater($gateway);
        $this->orderPayment->expects($this->never())->method($this->anything());

        for ($i = 0; $i < 4; $i++) {
            $this->assertNull($updater->latePayment($this->order()), 'case ' . $i);
        }
    }

    public function testLatePaymentWithoutSessionAsksNothing(): void
    {
        $this->info = [];
        $gateway = $this->createMock(Gateway::class);
        $gateway->expects($this->never())->method('session');
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertNull($this->updater($gateway)->latePayment($this->order()));
    }

    public function testLatePaymentStripeDownThrows(): void
    {
        $this->gateway->method('session')->willThrowException(RateLimitException::factory('slow down'));
        $this->orderPayment->expects($this->never())->method($this->anything());
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Stripe is not answering. Please try again in a minute.');

        $this->updater->latePayment($this->order());
    }

    public function testAbandonExpiresThenCancels(): void
    {
        $order = $this->order();
        $gateway = $this->createMock(Gateway::class);
        $gateway->expects($this->once())->method('expire')->with('sk_test_1', 'cs_test_1')
            ->willReturn(self::session('expired', null));
        $this->orderPayment->expects($this->once())->method('cancel')
            ->with($order, 'The customer came back from Stripe without paying.');

        $this->assertSame(ReturnResult::NOT_STARTED, $this->updater($gateway)->abandon($order));
    }

    /**
     * Stripe refused to expire because the customer paid meanwhile: the new answer (PAID) wins.
     */
    public function testAbandonKeepsOrderWhenExpireFailsAndTheSessionWasPaid(): void
    {
        $order = $this->order();
        $this->gateway->method('expire')->willThrowException(InvalidRequestException::factory('session is complete'));
        $this->gateway->method('session')->willReturn(self::session('complete', 'succeeded'));
        $this->orderPayment->expects($this->never())->method('cancel');
        $this->orderPayment->expects($this->once())->method('capture')->with($order, 'pi_1');

        $this->assertSame(ReturnResult::PAID, $this->updater->abandon($order));
    }

    /**
     * F5: expire failed (rate limit) and the session is still open: it can still
     * be paid, so the cart is not restored (UNCONFIRMED, not NOT_STARTED).
     */
    public function testAbandonWithStillOpenSessionIsUnconfirmed(): void
    {
        $this->gateway->method('expire')->willThrowException(RateLimitException::factory('slow down'));
        $this->gateway->method('session')->willReturn(self::session('open', null));
        $this->orderPayment->expects($this->never())->method('cancel');

        $this->assertSame(ReturnResult::UNCONFIRMED, $this->updater->abandon($this->order()));
    }

    public function testUpdateNeverCancelsAnOpenSession(): void
    {
        $this->gateway->method('session')->willReturn(self::session('open', null));
        $this->orderPayment->expects($this->never())->method('cancel');

        $this->assertSame(ReturnResult::NOT_STARTED, $this->updater->update($this->order()));
    }
}
