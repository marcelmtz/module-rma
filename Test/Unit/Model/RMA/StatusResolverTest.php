<?php

declare(strict_types=1);

namespace MageOS\RMA\Test\Unit\Model\RMA;

use MageOS\RMA\Api\Data\StatusInterface;
use MageOS\RMA\Api\Data\StatusSearchResultsInterface;
use MageOS\RMA\Api\StatusRepositoryInterface;
use MageOS\RMA\Model\RMA\StatusResolver;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StatusResolverTest extends TestCase
{
    private StatusRepositoryInterface&MockObject $statusRepository;
    private SearchCriteriaBuilderFactory&MockObject $searchCriteriaBuilderFactory;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private StatusResolver $resolver;

    protected function setUp(): void
    {
        $this->statusRepository = $this->createMock(StatusRepositoryInterface::class);
        $this->searchCriteriaBuilderFactory = $this->createMock(SearchCriteriaBuilderFactory::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);

        $this->searchCriteriaBuilderFactory->method('create')->willReturn($this->searchCriteriaBuilder);

        $this->resolver = new StatusResolver(
            $this->statusRepository,
            $this->searchCriteriaBuilderFactory
        );
    }

    // -------------------------------------------------------------------------
    // getCodeById
    // -------------------------------------------------------------------------

    public function testGetCodeByIdFetchesFromRepositoryOnCacheMiss(): void
    {
        $status = $this->createMock(StatusInterface::class);
        $status->method('getCode')->willReturn('approved');

        $this->statusRepository->expects($this->once())->method('get')->with(1)->willReturn($status);

        $result = $this->resolver->getCodeById(1);

        $this->assertSame('approved', $result);
    }

    public function testGetCodeByIdReturnsCachedValueWithoutRepositoryCall(): void
    {
        $status = $this->createMock(StatusInterface::class);
        $status->method('getCode')->willReturn('approved');

        $this->statusRepository->expects($this->once())->method('get')->willReturn($status);

        $this->resolver->getCodeById(1);
        $result = $this->resolver->getCodeById(1);

        $this->assertSame('approved', $result);
    }

    public function testGetCodeByIdReturnsNullWhenStatusNotFound(): void
    {
        $this->statusRepository->method('get')
            ->willThrowException(new NoSuchEntityException(__('Not found')));

        $result = $this->resolver->getCodeById(999);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // getIdByCode
    // -------------------------------------------------------------------------

    public function testGetIdByCodeFetchesFromRepository(): void
    {
        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $status = $this->createMock(StatusInterface::class);
        $status->method('getEntityId')->willReturn(3);
        $status->method('getCode')->willReturn('rejected');

        $searchResults = $this->createMock(StatusSearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([$status]);

        $this->statusRepository->method('getList')->willReturn($searchResults);

        $result = $this->resolver->getIdByCode('rejected');

        $this->assertSame(3, $result);
    }

    public function testGetIdByCodeReturnsCachedValueWithoutRepositoryCall(): void
    {
        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $status = $this->createMock(StatusInterface::class);
        $status->method('getEntityId')->willReturn(3);
        $status->method('getCode')->willReturn('rejected');

        $searchResults = $this->createMock(StatusSearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([$status]);

        $this->statusRepository->expects($this->once())->method('getList')->willReturn($searchResults);

        $this->resolver->getIdByCode('rejected');
        $result = $this->resolver->getIdByCode('rejected');

        $this->assertSame(3, $result);
    }

    public function testGetIdByCodeReturnsNullWhenNotFound(): void
    {
        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $searchResults = $this->createMock(StatusSearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([]);

        $this->statusRepository->method('getList')->willReturn($searchResults);

        $result = $this->resolver->getIdByCode('nonexistent_code');

        $this->assertNull($result);
    }

    public function testGetIdByCodeUsesFlippedCacheFromPriorGetCodeByIdCall(): void
    {
        $status = $this->createMock(StatusInterface::class);
        $status->method('getCode')->willReturn('approved');

        $this->statusRepository->expects($this->once())->method('get')->with(2)->willReturn($status);
        $this->statusRepository->expects($this->never())->method('getList');

        $this->resolver->getCodeById(2);

        $result = $this->resolver->getIdByCode('approved');

        $this->assertSame(2, $result);
    }
}
