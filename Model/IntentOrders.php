<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Which saved order, if any, was placed with a card PaymentIntent: its
 * payment's last transaction, or one of its payment transactions, is the
 * intent (authorize) or the intent's capture (`<pi>-capture`). Looked up
 * among the orders of the quote the intent was made for (metadata.quote_id,
 * which Standard::authorize() requires to be the order's quote), so the
 * query uses sales_order's quote_id index. Another order of the same quote
 * (a restored cart after a cancelled redirect payment) doesn't count.
 */
class IntentOrders
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return string|null The order's increment id, or null when no saved order uses the intent
     */
    public function orderFor(int $quoteId, string $intentId): ?string
    {
        if ($quoteId <= 0 || $intentId === '') {
            return null;
        }
        $connection = $this->resource->getConnection('sales');
        $ids = [$intentId, $intentId . '-capture'];
        $transactions = $connection->select()
            ->from(['t' => $this->resource->getTableName('sales_payment_transaction', 'sales')], ['t.transaction_id'])
            ->where('t.order_id = o.entity_id')
            ->where('t.txn_id IN (?)', $ids);
        $select = $connection->select()
            ->from(['o' => $this->resource->getTableName('sales_order', 'sales')], ['increment_id'])
            ->join(
                ['p' => $this->resource->getTableName('sales_order_payment', 'sales')],
                'p.parent_id = o.entity_id',
                []
            )
            ->where('o.quote_id = ?', $quoteId)
            ->where(
                $connection->quoteInto('p.last_trans_id IN (?)', $ids) . ' OR EXISTS (' . $transactions . ')'
            )
            ->limit(1);
        $incrementId = $connection->fetchOne($select);

        return $incrementId === false || $incrementId === null ? null : (string) $incrementId;
    }
}
