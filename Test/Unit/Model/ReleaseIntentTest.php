<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Test\Unit\Model;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Zero\Stripe\Model\Config;
use Zero\Stripe\Model\IntentOrders;
use Zero\Stripe\Model\PaymentIntentGateway;
use Zero\Stripe\Model\ReleaseIntent;

/**
 * Releasing the card hold of a failed placement: only this quote's held
 * intent, never one a saved order was placed with (IntentOrders, F2: another
 * order of a restored quote doesn't stop it), once per request, Stripe errors
 * only logged.
 */
#[AllowMockObjectsWithoutExpectations]
class ReleaseIntentTest extends TestCase
{
    private PaymentIntentGateway&MockObject $gateway;
    private IntentOrders&MockObject $intentOrders;
    private LoggerInterface&MockObject $logger;
    private string $secretKey = 'sk_test_1';

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(PaymentIntentGateway::class);
        $this->intentOrders = $this->createMock(IntentOrders::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testCancelsThisQuotesHeldIntent(): void
    {
        $this->intentOrders->expects($this->once())->method('orderFor')->with(55, 'pi_1')->willReturn(null);
        $this->gateway->expects($this->once())->method('retrieve')->with('sk_test_1', 'pi_1')
            ->willReturn($this->intent('requires_capture', '55'));
        $this->gateway->expects($this->once())->method('cancel')->with('sk_test_1', 'pi_1')
            ->willReturn($this->intent('canceled', '55'));
        $this->logger->expects($this->never())->method('warning');

        $this->releaseIntent()->release(55, 'pi_1', 1);
    }

    /**
     * F2: the quote was restored after a cancelled redirect order (another
     * sales_order row has quote_id 55). This is a wiring test: ReleaseIntent
     * asks IntentOrders about THIS intent (quote 55 AND pi_1), not just
     * whether the quote has an order, and releases when that answer is null.
     * That an order of the same quote with another intent gives null is
     * proven by IntentOrdersTest (the WHERE matches last_trans_id/txn_id
     * against pi_1 and pi_1-capture); the real MySQL query runs only in e2e.
     */
    public function testRestoredQuoteStillReleasesWhenNoOrderUsesTheIntent(): void
    {
        $this->intentOrders->expects($this->once())->method('orderFor')->with(55, 'pi_1')->willReturn(null);
        $this->gateway->method('retrieve')->willReturn($this->intent('requires_capture', '55'));
        $this->gateway->expects($this->once())->method('cancel')->with('sk_test_1', 'pi_1');

        $this->releaseIntent()->release(55, 'pi_1', 1);
    }

    public function testKeepsAnIntentASavedOrderWasPlacedWith(): void
    {
        $this->intentOrders->method('orderFor')->with(55, 'pi_1')->willReturn('000000077');
        $this->gateway->expects($this->never())->method('retrieve');
        $this->gateway->expects($this->never())->method('cancel');

        $this->releaseIntent()->release(55, 'pi_1', 1);
    }

    public function testLeavesAnotherQuotesIntent(): void
    {
        $this->intentOrders->method('orderFor')->willReturn(null);
        $this->gateway->method('retrieve')->willReturn($this->intent('requires_capture', '99'));
        $this->gateway->expects($this->never())->method('cancel');

        $this->releaseIntent()->release(55, 'pi_1', 1);
    }

    public function testIgnoresNonHeldIntent(): void
    {
        $this->intentOrders->method('orderFor')->willReturn(null);
        $this->gateway->method('retrieve')->willReturn($this->intent('succeeded', '55'));
        $this->gateway->expects($this->never())->method('cancel');

        $this->releaseIntent()->release(55, 'pi_1', 1);
    }

    public function testApiErrorOnlyLogged(): void
    {
        $this->intentOrders->method('orderFor')->willReturn(null);
        $this->gateway->method('retrieve')->willReturn($this->intent('requires_capture', '55'));
        $this->gateway->method('cancel')->willThrowException(InvalidRequestException::factory('Stripe down'));
        $this->logger->expects($this->once())->method('warning')
            ->with('Zero Stripe: could not release intent pi_1 of quote 55: Stripe down');

        $this->releaseIntent()->release(55, 'pi_1', 1);
    }

    public function testFailedReleaseIsTriedAgainInTheSameRequest(): void
    {
        $this->intentOrders->method('orderFor')->willReturn(null);
        $this->gateway->method('retrieve')->willReturn($this->intent('requires_capture', '55'));
        $calls = 0;
        $this->gateway->expects($this->exactly(2))->method('cancel')
            ->willReturnCallback(function () use (&$calls) {
                if (++$calls === 1) {
                    throw InvalidRequestException::factory('rate limit');
                }
                return $this->intent('canceled', '55');
            });

        $release = $this->releaseIntent();
        $release->release(55, 'pi_1', 1);
        $release->release(55, 'pi_1', 1);
    }

    /**
     * Both events (Zero's failure event and Magento's submit_failure) fire for one failure.
     */
    public function testReleasedOncePerRequest(): void
    {
        $this->intentOrders->method('orderFor')->willReturn(null);
        $this->gateway->expects($this->once())->method('retrieve')->willReturn($this->intent('requires_capture', '55'));
        $this->gateway->expects($this->once())->method('cancel');

        $release = $this->releaseIntent();
        $release->release(55, 'pi_1', 1);
        $release->release(55, 'pi_1', 1);
    }

    public function testNothingWithoutQuoteIntentOrSecretKey(): void
    {
        $this->intentOrders->expects($this->never())->method('orderFor');
        $this->gateway->expects($this->never())->method('retrieve');

        $this->releaseIntent()->release(0, 'pi_1', 1);
        $this->releaseIntent()->release(55, '', 1);
        $this->secretKey = '';
        $this->releaseIntent()->release(55, 'pi_1', 1);
    }

    private function releaseIntent(): ReleaseIntent
    {
        $config = $this->createStub(Config::class);
        $config->method('getSecretKey')->willReturn($this->secretKey);

        return new ReleaseIntent($config, $this->gateway, $this->intentOrders, $this->logger);
    }

    private function intent(string $status, string $quoteId): PaymentIntent
    {
        return PaymentIntent::constructFrom([
            'id' => 'pi_1',
            'status' => $status,
            'metadata' => ['quote_id' => $quoteId],
        ]);
    }
}
