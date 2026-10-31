<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Test\Unit\Controller\Checkout;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPaymentInfo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Checkout\Model\RedirectPayment\OrderLock;
use Zero\Checkout\Model\RedirectPayment\ReturnFlow;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Checkout\Test\Unit\Fake\FakeLockManager;
use Zero\Checkout\Test\Unit\Fake\OrderLockBuilder;
use Zero\Stripe\Controller\Checkout\Back;
use Zero\Stripe\Model\Checkout\OrderUpdater;
use Zero\Stripe\Model\Checkout\Payment;

/**
 * Back from Stripe Checkout runs under the order's lock (ReturnFlow): a
 * session still open is abandoned (expired, cancelled) and abandon()'s state
 * decides the redirect; any other state is used as it is.
 */
class BackTest extends TestCase
{
    use OrderLockBuilder;

    private OrderUpdater&MockObject $orderUpdater;
    private ReturnResult&MockObject $returnResult;
    private FakeLockManager $locks;
    private Order $order;

    protected function setUp(): void
    {
        $this->orderUpdater = $this->createMock(OrderUpdater::class);
        $this->returnResult = $this->createMock(ReturnResult::class);
        $this->locks = new FakeLockManager();
        $payment = $this->createStub(OrderPaymentInfo::class);
        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(9);
        $order->method('getEntityId')->willReturn(9);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $this->order = $order;
    }

    public function testOpenSessionIsAbandonedAndItsStateRedirects(): void
    {
        $this->returnResult->method('lastOrder')->with(Payment::CODE, '000000123')->willReturn($this->order);
        $this->orderUpdater->expects($this->once())->method('update')->willReturn(ReturnResult::NOT_STARTED);
        $this->orderUpdater->expects($this->once())->method('abandon')->with($this->order)
            ->willReturn(ReturnResult::NOT_STARTED);
        $redirect = $this->createStub(Redirect::class);
        $this->returnResult->expects($this->once())->method('redirect')
            ->with(ReturnResult::NOT_STARTED, 'Stripe')->willReturn($redirect);

        $this->assertSame($redirect, $this->back()->execute());
        $this->assertNotEmpty($this->locks->locks(), 'the update runs under the order lock');
    }

    /**
     * F5: Stripe wouldn't expire a session still open, abandon() says UNCONFIRMED: the cart isn't restored.
     */
    public function testAbandonsUnconfirmedAnswerWins(): void
    {
        $this->returnResult->method('lastOrder')->willReturn($this->order);
        $this->orderUpdater->method('update')->willReturn(ReturnResult::NOT_STARTED);
        $this->orderUpdater->expects($this->once())->method('abandon')->willReturn(ReturnResult::UNCONFIRMED);
        $this->returnResult->expects($this->once())->method('redirect')
            ->with(ReturnResult::UNCONFIRMED, 'Stripe')->willReturn($this->createStub(Redirect::class));

        $this->back()->execute();
    }

    public function testPaidSessionIsNotAbandoned(): void
    {
        $this->returnResult->method('lastOrder')->willReturn($this->order);
        $this->orderUpdater->expects($this->once())->method('update')->willReturn(ReturnResult::PAID);
        $this->orderUpdater->expects($this->never())->method('abandon');
        $this->returnResult->expects($this->once())->method('redirect')
            ->with(ReturnResult::PAID, 'Stripe')->willReturn($this->createStub(Redirect::class));

        $this->back()->execute();
    }

    public function testLockHeldByAnotherProcessIsPendingWithoutAskingStripe(): void
    {
        $this->returnResult->method('lastOrder')->willReturn($this->order);
        $this->locks->holdByOther(OrderLock::PREFIX . '000000123');
        $this->orderUpdater->expects($this->never())->method('update');
        $this->orderUpdater->expects($this->never())->method('abandon');
        $this->returnResult->expects($this->once())->method('redirect')
            ->with(ReturnResult::PENDING, 'Stripe')->willReturn($this->createStub(Redirect::class));

        $this->back()->execute();
    }

    public function testNoOrderGoesElsewhere(): void
    {
        $this->returnResult->method('lastOrder')->willReturn(null);
        $redirect = $this->createStub(Redirect::class);
        $this->returnResult->expects($this->once())->method('elsewhere')->willReturn($redirect);
        $this->orderUpdater->expects($this->never())->method('update');

        $this->assertSame($redirect, $this->back()->execute());
    }

    private function back(): Back
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['order', null, '000000123']]);

        return new Back(
            $request,
            $this->returnResult,
            $this->orderUpdater,
            new ReturnFlow($this->orderLock($this->locks), $this->createStub(LoggerInterface::class))
        );
    }
}
