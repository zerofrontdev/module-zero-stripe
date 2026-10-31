<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\Stripe\Model\CaptureAfterPlace;

/**
 * A saved Stripe card order is invoiced online (capture) only when it holds
 * an open authorization (F3); a failed capture raises an admin notice that
 * points to the log (R9, F4) and goes on to the caller.
 */
class CaptureAfterPlaceTest extends TestCase
{
    private InvoiceOrderInterface&MockObject $invoiceOrder;
    private NotifierInterface&MockObject $notifier;

    protected function setUp(): void
    {
        $this->invoiceOrder = $this->createMock(InvoiceOrderInterface::class);
        $this->notifier = $this->createMock(NotifierInterface::class);
    }

    public function testInvoicesOnlineForAuthorizedStripeOrder(): void
    {
        $this->invoiceOrder->expects($this->once())->method('execute')->with(12, true, [], false)->willReturn(5);
        $this->notifier->expects($this->never())->method('addMajor');

        $this->capture()->execute($this->order('zero_stripe', true, $this->auth(false)), []);
    }

    public function testIgnoresOtherMethods(): void
    {
        $this->invoiceOrder->expects($this->never())->method('execute');
        $this->notifier->expects($this->never())->method('addMajor');

        $this->capture()->execute($this->order('checkmo', true, $this->auth(false)), []);
    }

    public function testIgnoresOrderThatCannotBeInvoiced(): void
    {
        $this->invoiceOrder->expects($this->never())->method('execute');
        $this->notifier->expects($this->never())->method('addMajor');

        $this->capture()->execute($this->order('zero_stripe', false, $this->auth(false)), []);
    }

    public function testIgnoresOrderWithoutAuthorization(): void
    {
        $this->invoiceOrder->expects($this->never())->method('execute');
        $this->notifier->expects($this->never())->method('addMajor');

        $this->capture()->execute($this->order('zero_stripe', true, false), []);
    }

    /**
     * F3: with a closed authorization Magento would mark the invoice paid offline.
     */
    public function testIgnoresClosedAuthorization(): void
    {
        $this->invoiceOrder->expects($this->never())->method('execute');
        $this->notifier->expects($this->never())->method('addMajor');

        $this->capture()->execute($this->order('zero_stripe', true, $this->auth(true)), []);
    }

    public function testFailedCaptureRaisesAdminNoticeAndRethrows(): void
    {
        $failure = new LocalizedException(__('Could not save an invoice, see error log for details'));
        $this->invoiceOrder->expects($this->once())->method('execute')->willThrowException($failure);
        $this->notifier->expects($this->once())->method('addMajor')->with(
            'Card payment of order #000000042 was not captured',
            $this->logicalAnd(
                $this->stringContains('the capture failed: Could not save an invoice, see error log for details'),
                $this->stringContains('(details in var/log/exception.log)'),
                $this->stringContains('Capture Online')
            )
        );

        try {
            $this->capture()->execute($this->order('zero_stripe', true, $this->auth(false)), []);
            $this->fail('The capture failure must reach the caller.');
        } catch (LocalizedException $e) {
            $this->assertSame($failure, $e);
        }
    }

    private function capture(): CaptureAfterPlace
    {
        return new CaptureAfterPlace($this->invoiceOrder, $this->notifier);
    }

    private function auth(bool $closed): Transaction
    {
        $auth = $this->createStub(Transaction::class);
        $auth->method('getIsClosed')->willReturn($closed ? '1' : '0');

        return $auth;
    }

    private function order(string $method, bool $canInvoice, Transaction|false $auth): Order
    {
        $payment = $this->createStub(Payment::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getAuthorizationTransaction')->willReturn($auth);
        $order = $this->createStub(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('canInvoice')->willReturn($canInvoice);
        $order->method('getEntityId')->willReturn('12');
        $order->method('getIncrementId')->willReturn('000000042');

        return $order;
    }
}
