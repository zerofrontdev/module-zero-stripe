<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Zero\Stripe\Model\IntentOrders;

/**
 * The SQL that finds a saved order placed with a PaymentIntent: limited to
 * the intent's quote, matching the payment's last transaction or any of the
 * order's transactions against `<pi>` and `<pi>-capture`. The connection is a
 * recording fake; the real query runs against MySQL only in e2e.
 */
class IntentOrdersTest extends TestCase
{
    /** @var list<array{int, string, mixed}> select number, call, arguments */
    private array $calls = [];
    private int $selects = 0;
    private mixed $fetchOneResult = false;
    private int $fetches = 0;

    public function testBuildsTheQuoteScopedLookup(): void
    {
        $this->fetchOneResult = '000000077';

        $this->assertSame('000000077', $this->intentOrders()->orderFor(55, 'pi_1'));

        $this->assertSame(2, $this->selects);
        // Select #1: the correlated transactions subquery.
        $this->assertContains([1, 'from', [['t' => 'pfx_sales_payment_transaction'], ['t.transaction_id']]], $this->calls);
        $this->assertContains([1, 'where', ['t.order_id = o.entity_id', null]], $this->calls);
        $this->assertContains([1, 'where', ['t.txn_id IN (?)', ['pi_1', 'pi_1-capture']]], $this->calls);
        // Select #2: the order of this quote.
        $this->assertContains([2, 'from', [['o' => 'pfx_sales_order'], ['increment_id']]], $this->calls);
        $this->assertContains(
            [2, 'join', [['p' => 'pfx_sales_order_payment'], 'p.parent_id = o.entity_id', []]],
            $this->calls
        );
        $this->assertContains([2, 'where', ['o.quote_id = ?', 55]], $this->calls);
        $this->assertContains(
            [2, 'where', ["p.last_trans_id IN ('pi_1','pi_1-capture') OR EXISTS (SUBQUERY#1)", null]],
            $this->calls
        );
        $this->assertContains([2, 'limit', [1]], $this->calls);
        $this->assertSame(1, $this->fetches);
    }

    public function testNoOrderGivesNull(): void
    {
        $this->fetchOneResult = false;

        $this->assertNull($this->intentOrders()->orderFor(55, 'pi_1'));
        $this->assertSame(1, $this->fetches);
    }

    public function testNoQuoteOrNoIntentAsksNothing(): void
    {
        $this->assertNull($this->intentOrders()->orderFor(0, 'pi_1'));
        $this->assertNull($this->intentOrders()->orderFor(55, ''));
        $this->assertSame(0, $this->selects);
        $this->assertSame(0, $this->fetches);
    }

    private function intentOrders(): IntentOrders
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->select(++$this->selects));
        $connection->method('quoteInto')->willReturnCallback(
            fn (string $text, $value) => str_replace(
                '?',
                implode(',', array_map(fn ($v) => "'" . $v . "'", (array) $value)),
                $text
            )
        );
        $connection->method('fetchOne')->willReturnCallback(function () {
            $this->fetches++;
            return $this->fetchOneResult;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturnCallback(
            fn (string $name) => $name === 'sales' ? $connection : null
        );
        $resource->method('getTableName')->willReturnCallback(fn (string $table) => 'pfx_' . $table);

        return new IntentOrders($resource);
    }

    private function select(int $number): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnCallback(function ($name, $cols) use ($select, $number) {
            $this->calls[] = [$number, 'from', [$name, $cols]];
            return $select;
        });
        $select->method('join')->willReturnCallback(function ($name, $cond, $cols) use ($select, $number) {
            $this->calls[] = [$number, 'join', [$name, $cond, $cols]];
            return $select;
        });
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select, $number) {
            $this->calls[] = [$number, 'where', [$cond, $value]];
            return $select;
        });
        $select->method('limit')->willReturnCallback(function ($count) use ($select, $number) {
            $this->calls[] = [$number, 'limit', [$count]];
            return $select;
        });
        $select->method('__toString')->willReturn('SUBQUERY#' . $number);

        return $select;
    }
}
