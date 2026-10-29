<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Model\Checkout;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;

/**
 * Changes the Checkout Session Stripe Checkout is about to create for an
 * order (registered in di.xml, `sessionModifiers` of Payment): Zero_Subscriptions
 * turns it into a subscription when the order has subscription products.
 */
interface SessionParamsInterface
{
    /**
     * @param array<string, mixed> $params The session as Payment built it (mode "payment", one line for the order)
     * @return array<string, mixed>
     * @throws LocalizedException To stop the order with a message
     */
    public function modify(Order $order, array $params): array;
}
