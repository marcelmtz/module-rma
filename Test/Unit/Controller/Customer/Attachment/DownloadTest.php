<?php

declare(strict_types=1);

namespace MageOS\RMA\Test\Unit\Controller\Customer\Attachment;

use MageOS\RMA\Api\AttachmentRepositoryInterface;
use MageOS\RMA\Api\Data\AttachmentInterface;
use MageOS\RMA\Api\Data\RMAInterface;
use MageOS\RMA\Api\RMARepositoryInterface;
use MageOS\RMA\Controller\Customer\Attachment\Download;
use MageOS\RMA\Service\AttachmentService;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DownloadTest extends TestCase
{
    private RequestInterface&MockObject $request;
    private RedirectFactory&MockObject $resultRedirectFactory;
    private FileFactory&MockObject $fileFactory;
    private CustomerSession&MockObject $customerSession;
    private AttachmentRepositoryInterface&MockObject $attachmentRepository;
    private RMARepositoryInterface&MockObject $rmaRepository;
    private AttachmentService&MockObject $attachmentService;
    private MessageManagerInterface&MockObject $messageManager;
    private Download $controller;

    protected function setUp(): void
    {
        $this->request               = $this->createMock(RequestInterface::class);
        $this->resultRedirectFactory = $this->createMock(RedirectFactory::class);
        $this->fileFactory           = $this->createMock(FileFactory::class);
        $this->customerSession       = $this->createMock(CustomerSession::class);
        $this->attachmentRepository  = $this->createMock(AttachmentRepositoryInterface::class);
        $this->rmaRepository         = $this->createMock(RMARepositoryInterface::class);
        $this->attachmentService     = $this->createMock(AttachmentService::class);
        $this->messageManager        = $this->createMock(MessageManagerInterface::class);

        $this->controller = new Download(
            $this->request,
            $this->resultRedirectFactory,
            $this->fileFactory,
            $this->customerSession,
            $this->attachmentRepository,
            $this->rmaRepository,
            $this->attachmentService,
            $this->messageManager
        );
    }

    private function makeRedirect(string $path): Redirect&MockObject
    {
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with($path)->willReturnSelf();

        return $redirect;
    }

    // -------------------------------------------------------------------------
    // Authentication gate
    // -------------------------------------------------------------------------

    public function testRedirectsToLoginWhenCustomerNotLoggedIn(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $redirect = $this->makeRedirect('customer/account/login');
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $result = $this->controller->execute();

        $this->assertSame($redirect, $result);
    }

    public function testDoesNotAccessRepositoryWhenCustomerNotLoggedIn(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->attachmentRepository->expects($this->never())->method('get');

        $this->controller->execute();
    }

    // -------------------------------------------------------------------------
    // Ownership check — the critical security gate
    // -------------------------------------------------------------------------

    public function testRedirectsWithErrorWhenRmaDoesNotBelongToCustomer(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(42);

        $this->request->method('getParam')->with('id')->willReturn('7');

        $attachment = $this->createMock(AttachmentInterface::class);
        $attachment->method('getRmaId')->willReturn(10);
        $this->attachmentRepository->method('get')->with(7)->willReturn($attachment);

        $rma = $this->createMock(RMAInterface::class);
        $rma->method('getCustomerId')->willReturn(99);
        $this->rmaRepository->method('get')->with(10)->willReturn($rma);

        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->anything());

        $redirect = $this->makeRedirect('rma/customer/history');
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $result = $this->controller->execute();

        $this->assertSame($redirect, $result);
    }

    public function testDoesNotServeFileWhenRmaBelongsToDifferentCustomer(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(1);

        $this->request->method('getParam')->with('id')->willReturn('5');

        $attachment = $this->createMock(AttachmentInterface::class);
        $attachment->method('getRmaId')->willReturn(10);
        $this->attachmentRepository->method('get')->willReturn($attachment);

        $rma = $this->createMock(RMAInterface::class);
        $rma->method('getCustomerId')->willReturn(2);
        $this->rmaRepository->method('get')->willReturn($rma);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->attachmentService->expects($this->never())->method('createDownloadResponse');

        $this->controller->execute();
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function testReturnsDownloadResponseForAuthorizedCustomer(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(42);

        $this->request->method('getParam')->with('id')->willReturn('7');

        $attachment = $this->createMock(AttachmentInterface::class);
        $attachment->method('getRmaId')->willReturn(10);
        $this->attachmentRepository->method('get')->with(7)->willReturn($attachment);

        $rma = $this->createMock(RMAInterface::class);
        $rma->method('getCustomerId')->willReturn(42);
        $this->rmaRepository->method('get')->with(10)->willReturn($rma);

        $downloadResponse = $this->createMock(ResponseInterface::class);
        $this->attachmentService->expects($this->once())
            ->method('createDownloadResponse')
            ->with($attachment, $this->fileFactory)
            ->willReturn($downloadResponse);

        $result = $this->controller->execute();

        $this->assertSame($downloadResponse, $result);
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    public function testRedirectsWithErrorWhenAttachmentNotFound(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->request->method('getParam')->willReturn('99');

        $this->attachmentRepository->method('get')
            ->willThrowException(new NoSuchEntityException(__('Not found')));

        $this->messageManager->expects($this->once())->method('addErrorMessage');

        $redirect = $this->makeRedirect('rma/customer/history');
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $result = $this->controller->execute();

        $this->assertSame($redirect, $result);
    }

    public function testRedirectsWithErrorOnGenericException(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(1);

        $this->request->method('getParam')->willReturn('5');

        $attachment = $this->createMock(AttachmentInterface::class);
        $attachment->method('getRmaId')->willReturn(10);
        $this->attachmentRepository->method('get')->willReturn($attachment);

        $rma = $this->createMock(RMAInterface::class);
        $rma->method('getCustomerId')->willReturn(1);
        $this->rmaRepository->method('get')->willReturn($rma);

        $this->attachmentService->method('createDownloadResponse')
            ->willThrowException(new \Exception('Unexpected error'));

        $this->messageManager->expects($this->once())->method('addErrorMessage');

        $redirect = $this->makeRedirect('rma/customer/history');
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $result = $this->controller->execute();

        $this->assertSame($redirect, $result);
    }
}
