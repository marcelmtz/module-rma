<?php

declare(strict_types=1);

namespace MageOS\RMA\Test\Unit\Service;

use MageOS\RMA\Helper\ModuleConfig;
use MageOS\RMA\Model\ResourceModel\Item\CollectionFactory as RmaItemCollectionFactory;
use MageOS\RMA\Service\OrderEligibility;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderEligibilityTest extends TestCase
{
    private ModuleConfig&MockObject $moduleConfig;
    private RmaItemCollectionFactory&MockObject $rmaItemCollectionFactory;
    private OrderCollectionFactory&MockObject $orderCollectionFactory;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private TimezoneInterface&MockObject $timezone;
    private StoreManagerInterface&MockObject $storeManager;

    protected function setUp(): void
    {
        $this->moduleConfig = $this->createMock(ModuleConfig::class);
        $this->rmaItemCollectionFactory = $this->createMock(RmaItemCollectionFactory::class);
        $this->orderCollectionFactory = $this->createMock(OrderCollectionFactory::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->timezone = $this->createMock(TimezoneInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
    }

    private function createService(array $stubbedMethods = []): OrderEligibility
    {
        if (empty($stubbedMethods)) {
            return new OrderEligibility(
                $this->moduleConfig,
                $this->rmaItemCollectionFactory,
                $this->orderCollectionFactory,
                $this->orderRepository,
                $this->timezone,
                $this->storeManager
            );
        }

        return $this->getMockBuilder(OrderEligibility::class)
            ->setConstructorArgs([
                $this->moduleConfig,
                $this->rmaItemCollectionFactory,
                $this->orderCollectionFactory,
                $this->orderRepository,
                $this->timezone,
                $this->storeManager,
            ])
            ->onlyMethods($stubbedMethods)
            ->getMock();
    }

    private function createOrder(int $storeId = 1, string $status = 'complete', string $createdAt = ''): OrderInterface&MockObject
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn($storeId);
        $order->method('getStatus')->willReturn($status);
        $order->method('getCreatedAt')->willReturn($createdAt ?: date('Y-m-d H:i:s'));
        $order->method('getEntityId')->willReturn(100);

        return $order;
    }

    // -------------------------------------------------------------------------
    // isOrderEligible
    // -------------------------------------------------------------------------

    public function testIsOrderEligibleReturnsFalseWhenModuleDisabled(): void
    {
        $this->moduleConfig->method('isEnabled')->with(1)->willReturn(false);
        $service = $this->createService(['getEligibleItems']);

        $this->assertFalse($service->isOrderEligible($this->createOrder()));
    }

    public function testIsOrderEligibleReturnsFalseWhenStatusNotAllowed(): void
    {
        $this->moduleConfig->method('isEnabled')->willReturn(true);
        $this->moduleConfig->method('getAllowedOrderStatuses')->willReturn(['complete']);

        $order = $this->createOrder(status: 'pending');
        $service = $this->createService(['getEligibleItems']);

        $this->assertFalse($service->isOrderEligible($order));
    }

    public function testIsOrderEligibleReturnsFalseWhenOutsideReturnPeriod(): void
    {
        $this->moduleConfig->method('isEnabled')->willReturn(true);
        $this->moduleConfig->method('getAllowedOrderStatuses')->willReturn(['complete']);
        $this->moduleConfig->method('getReturnPeriod')->willReturn(30);

        $order = $this->createOrder(status: 'complete', createdAt: '2020-01-01 00:00:00');
        $service = $this->createService(['getEligibleItems']);

        $this->assertFalse($service->isOrderEligible($order));
    }

    public function testIsOrderEligibleReturnsFalseWhenNoEligibleItems(): void
    {
        $this->moduleConfig->method('isEnabled')->willReturn(true);
        $this->moduleConfig->method('getAllowedOrderStatuses')->willReturn(['complete']);
        $this->moduleConfig->method('getReturnPeriod')->willReturn(0);

        $order = $this->createOrder(status: 'complete');

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getEligibleItems']);
        $service->method('getEligibleItems')->with($order)->willReturn([]);

        $this->assertFalse($service->isOrderEligible($order));
    }

    public function testIsOrderEligibleReturnsTrueWhenAllConditionsMet(): void
    {
        $this->moduleConfig->method('isEnabled')->willReturn(true);
        $this->moduleConfig->method('getAllowedOrderStatuses')->willReturn(['complete']);
        $this->moduleConfig->method('getReturnPeriod')->willReturn(0);

        $order = $this->createOrder(status: 'complete');

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getEligibleItems']);
        $service->method('getEligibleItems')->with($order)->willReturn([
            ['order_item_id' => 1, 'name' => 'Product', 'sku' => 'SKU-1', 'qty_ordered' => 2, 'qty_already_requested' => 0, 'qty_available' => 2],
        ]);

        $this->assertTrue($service->isOrderEligible($order));
    }

    public function testIsOrderEligibleReturnsTrueWhenReturnPeriodIsUnlimited(): void
    {
        $this->moduleConfig->method('isEnabled')->willReturn(true);
        $this->moduleConfig->method('getAllowedOrderStatuses')->willReturn(['complete']);
        $this->moduleConfig->method('getReturnPeriod')->willReturn(0);

        $order = $this->createOrder(status: 'complete', createdAt: '2010-01-01 00:00:00');

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getEligibleItems']);
        $service->method('getEligibleItems')->willReturn([['order_item_id' => 1]]);

        $this->assertTrue($service->isOrderEligible($order));
    }

    // -------------------------------------------------------------------------
    // getEligibleItems
    // -------------------------------------------------------------------------

    private function createOrderItem(
        ?int $parentItemId,
        string $productType,
        int $itemId,
        int $qtyOrdered,
        string $name = 'Product',
        string $sku = 'SKU-001'
    ): OrderItemInterface&MockObject {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getParentItemId')->willReturn($parentItemId);
        $item->method('getProductType')->willReturn($productType);
        $item->method('getItemId')->willReturn($itemId);
        $item->method('getQtyOrdered')->willReturn($qtyOrdered);
        $item->method('getName')->willReturn($name);
        $item->method('getSku')->willReturn($sku);

        return $item;
    }

    public function testGetEligibleItemsSkipsChildItems(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: 5, productType: 'simple', itemId: 10, qtyOrdered: 1),
        ]);

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([]);

        $this->assertSame([], $service->getEligibleItems($order));
    }

    public function testGetEligibleItemsSkipsVirtualProducts(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: null, productType: 'virtual', itemId: 10, qtyOrdered: 1),
        ]);

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([]);

        $this->assertSame([], $service->getEligibleItems($order));
    }

    public function testGetEligibleItemsSkipsDownloadableProducts(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: null, productType: 'downloadable', itemId: 10, qtyOrdered: 1),
        ]);

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([]);

        $this->assertSame([], $service->getEligibleItems($order));
    }

    public function testGetEligibleItemsSkipsItemsWithNoAvailableQty(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: null, productType: 'simple', itemId: 10, qtyOrdered: 2),
        ]);

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([10 => 2]);

        $this->assertSame([], $service->getEligibleItems($order));
    }

    public function testGetEligibleItemsDeductsAlreadyRequestedQty(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: null, productType: 'simple', itemId: 10, qtyOrdered: 3, name: 'Shirt', sku: 'SHIRT-L'),
        ]);

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([10 => 1]);

        $result = $service->getEligibleItems($order);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['qty_already_requested']);
        $this->assertSame(2, $result[0]['qty_available']);
    }

    public function testGetEligibleItemsReturnsCorrectStructure(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: null, productType: 'simple', itemId: 10, qtyOrdered: 2, name: 'Blue Hat', sku: 'HAT-BL'),
        ]);

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([]);

        $result = $service->getEligibleItems($order);

        $this->assertCount(1, $result);
        $this->assertSame([
            'order_item_id' => 10,
            'name' => 'Blue Hat',
            'sku' => 'HAT-BL',
            'qty_ordered' => 2,
            'qty_already_requested' => 0,
            'qty_available' => 2,
        ], $result[0]);
    }

    public function testGetEligibleItemsFiltersMultipleItemTypes(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: null, productType: 'simple', itemId: 1, qtyOrdered: 2, name: 'Book', sku: 'BOOK-1'),
            $this->createOrderItem(parentItemId: null, productType: 'virtual', itemId: 2, qtyOrdered: 1),
            $this->createOrderItem(parentItemId: 1, productType: 'simple', itemId: 3, qtyOrdered: 1),
            $this->createOrderItem(parentItemId: null, productType: 'downloadable', itemId: 4, qtyOrdered: 1),
            $this->createOrderItem(parentItemId: null, productType: 'simple', itemId: 5, qtyOrdered: 1, name: 'Pen', sku: 'PEN-1'),
        ]);

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([]);

        $result = $service->getEligibleItems($order);

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['order_item_id']);
        $this->assertSame(5, $result[1]['order_item_id']);
    }
}
