<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model\Pdf;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\Data\RefundItemInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\Pdf\RefundReceipt;
use Acme\SellerRefund\Model\SellerLineResolver;
use Acme\SellerRefund\Model\Tax\TaxCodeResolver;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers the reissued receipt / qualified tax invoice generator (RefundReceipt).
 * Verifies that tax rate groups are aggregated correctly per rate and that pre-refund
 * header totals strictly derive from seller lines, ignoring core (first-party) items on mixed orders.
 */
class RefundReceiptTest extends TestCase
{
    public function testBuildDataGroupsTaxAndIgnoresCoreItemsOnMixedOrders(): void
    {
        $refundRepository = $this->createMock(RefundRepositoryInterface::class);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $sellerLineResolver = $this->createMock(SellerLineResolver::class);
        $taxCodeResolver = $this->createMock(TaxCodeResolver::class);
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime('2026-09-16 10:00:00'));

        $calculator = new RefundTotalCalculator($sellerLineResolver, $taxCodeResolver);

        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getEntityId')->willReturn(101);
        $refund->method('getOrderId')->willReturn(42);
        $refund->method('getRefundNo')->willReturn('SR-20260916-000101');
        $refund->method('getRefundType')->willReturn(RefundInterface::TYPE_PARTIAL);
        $refund->method('getCurrencyCode')->willReturn('JPY');
        $refund->method('getCreatedAt')->willReturn('2026-09-16 10:00:00');

        // Mixed order setup: 1 seller item (ID 10) and 1 core first-party item (ID 20)
        $sellerItem = $this->createMock(OrderItem::class);
        $sellerItem->method('getItemId')->willReturn(10);
        $sellerItem->method('getSku')->willReturn('SELLER-RED-01');
        $sellerItem->method('getName')->willReturn('Red Seller Item');
        $sellerItem->method('getPrice')->willReturn(1000.0);
        $sellerItem->method('getQtyOrdered')->willReturn(2.0);
        $sellerItem->method('getData')->willReturnCallback(
            static fn(string $key) => match ($key) {
                'mp_seller_code' => 'SLR-100',
                'mp_tax_class' => '1',
                default => null,
            }
        );

        $coreItem = $this->createMock(OrderItem::class);
        $coreItem->method('getItemId')->willReturn(20);
        $coreItem->method('getSku')->willReturn('CORE-ITEM-99');
        $coreItem->method('getName')->willReturn('First-Party Core Item');
        $coreItem->method('getPrice')->willReturn(5000.0);
        $coreItem->method('getQtyOrdered')->willReturn(1.0);

        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getShippingAmount')->willReturn(500.0);
        $order->method('getOrderCurrencyCode')->willReturn('JPY');
        $order->method('getAllVisibleItems')->willReturn([$sellerItem, $coreItem]);

        $sellerLineResolver->method('sellerLines')->willReturn([10 => $sellerItem]);
        $taxCodeResolver->method('toBusinessCode')->with(1)->willReturn('010');
        $taxCodeResolver->method('rateFor')->with('010')->willReturn('0.1000');

        $orderRepository->method('get')->with(42)->willReturn($order);

        // Snapshot line: 1 qty of seller line refunded
        $refundItem = $this->createMock(RefundItemInterface::class);
        $refundItem->method('getOrderItemId')->willReturn(10);
        $refundItem->method('getSku')->willReturn('SELLER-RED-01');
        $refundItem->method('getProductName')->willReturn('Red Seller Item');
        $refundItem->method('getQtyRefund')->willReturn('1.0000');
        $refundItem->method('getUnitPrice')->willReturn('1000.0000');
        $refundItem->method('getRowAmount')->willReturn('1000.0000');
        $refundItem->method('getShippingAmount')->willReturn('0.0000');
        $refundItem->method('getTaxRate')->willReturn('0.1000');
        $refundItem->method('getTaxCode')->willReturn('010');
        $refundItem->method('getTaxAmount')->willReturn('100.0000');
        $refundItem->method('getGrandTotal')->willReturn('1100.0000');

        $refundRepository->method('getItems')->with(101)->willReturn([$refundItem]);

        $receipt = new RefundReceipt($refundRepository, $orderRepository, $calculator, $timezone);
        $data = $receipt->buildData($refund);

        // Verify basic properties
        self::assertSame('SR-20260916-000101', $data['refund_no']);
        self::assertSame('JPY', $data['currency']);
        self::assertTrue($data['is_partial']);

        // Verify pre-refund figures derive ONLY from seller lines (subtotal = 2000, shipping = 500, tax = 250, grand_total = 2750)
        // Core item ($5000) must be completely excluded!
        self::assertSame('2000.0000', $data['pre_refund']['subtotal']);
        self::assertSame('500.0000', $data['pre_refund']['shipping']);
        self::assertSame('250.0000', $data['pre_refund']['tax']);
        self::assertSame('2750.0000', $data['pre_refund']['grand_total']);

        // Verify refund totals
        self::assertSame('1000.0000', $data['refund']['subtotal']);
        self::assertSame('0.0000', $data['refund']['shipping']);
        self::assertSame('100.0000', $data['refund']['tax']);
        self::assertSame('1100.0000', $data['refund']['grand_total']);

        // Verify tax groups aggregation
        self::assertCount(1, $data['tax_groups']);
        self::assertSame([
            [
                'rate' => '0.1000',
                'taxable' => '1000.0000',
                'tax' => '100.0000',
            ],
        ], $data['tax_groups']);
    }
}
