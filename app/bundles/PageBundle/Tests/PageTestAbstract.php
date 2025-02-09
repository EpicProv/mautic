<?php

namespace Mautic\PageBundle\Tests;

use Doctrine\ORM\EntityManager;
use Mautic\CoreBundle\Helper\CookieHelper;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\IpLookupHelper;
use Mautic\CoreBundle\Helper\UrlHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\LeadBundle\Helper\ContactRequestHelper;
use Mautic\LeadBundle\Model\CompanyModel;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Tracker\ContactTracker;
use Mautic\LeadBundle\Tracker\DeviceTracker;
use Mautic\PageBundle\Entity\HitRepository;
use Mautic\PageBundle\Entity\PageRepository;
use Mautic\PageBundle\Model\PageModel;
use Mautic\PageBundle\Model\RedirectModel;
use Mautic\PageBundle\Model\TrackableModel;
use Mautic\QueueBundle\Queue\QueueService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\EventDispatcher\EventDispatcher;

class PageTestAbstract extends TestCase
{
    protected static $mockId   = 123;
    protected static $mockName = 'Mock test name';
    protected string $mockTrackingId;

    /**
     * @var Router|MockObject
     */
    protected $router;

    protected function setUp(): void
    {
        $this->mockTrackingId = hash('sha1', uniqid(mt_rand(), true));
    }

    /**
     * @return PageModel
     */
    protected function getPageModel($transliterationEnabled = true)
    {
        $cookieHelper = $this
            ->getMockBuilder(CookieHelper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->router = $this->createMock(Router::class);

        $ipLookupHelper = $this
            ->getMockBuilder(IpLookupHelper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $leadModel = $this
            ->getMockBuilder(LeadModel::class)
            ->disableOriginalConstructor()
            ->getMock();

        $leadFieldModel = $this
            ->getMockBuilder(FieldModel::class)
            ->disableOriginalConstructor()
            ->getMock();

        $redirectModel = $this->getRedirectModel();

        $companyModel = $this
            ->getMockBuilder(CompanyModel::class)
            ->disableOriginalConstructor()
            ->getMock();

        $trackableModel = $this
            ->getMockBuilder(TrackableModel::class)
            ->disableOriginalConstructor()
            ->getMock();

        $dispatcher = $this
            ->getMockBuilder(EventDispatcher::class)
            ->disableOriginalConstructor()
            ->getMock();

        $translator = $this
            ->getMockBuilder(Translator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $entityManager = $this
            ->getMockBuilder(EntityManager::class)
            ->disableOriginalConstructor()
            ->getMock();

        $pageRepository = $this
            ->getMockBuilder(PageRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $coreParametersHelper = $this
            ->getMockBuilder(CoreParametersHelper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $hitRepository = $this->createMock(HitRepository::class);
        $userHelper    = $this->createMock(UserHelper::class);

        $queueService = $this
            ->getMockBuilder(QueueService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $contactTracker = $this->createMock(ContactTracker::class);

        /** @var ContactRequestHelper&MockObject $contactRequestHelper */
        $contactRequestHelper = $this->createMock(ContactRequestHelper::class);

        $contactTracker->expects($this
            ->any())
            ->method('getContact')
            ->willReturn($this
                ->returnValue(['id' => self::$mockId, 'name' => self::$mockName])
            );

        $queueService->expects($this
            ->any())
            ->method('isQueueEnabled')
            ->will(
                $this->returnValue(false)
            );

        $entityManager->expects($this
            ->any())
            ->method('getRepository')
            ->will(
                $this->returnValueMap(
                    [
                        [\Mautic\PageBundle\Entity\Page::class, $pageRepository],
                        [\Mautic\PageBundle\Entity\Hit::class, $hitRepository],
                    ]
                )
            );

        $coreParametersHelper->expects($this->any())
                ->method('get')
                ->with('transliterate_page_title')
                ->willReturn($transliterationEnabled);

        $deviceTrackerMock = $this->createMock(DeviceTracker::class);

        $pageModel = new PageModel(
            $cookieHelper,
            $ipLookupHelper,
            $leadModel,
            $leadFieldModel,
            $redirectModel,
            $trackableModel,
            $queueService,
            $companyModel,
            $deviceTrackerMock,
            $contactTracker,
            $coreParametersHelper,
            $contactRequestHelper
        );

        $pageModel->setDispatcher($dispatcher);
        $pageModel->setTranslator($translator);
        $pageModel->setEntityManager($entityManager);
        $pageModel->setRouter($this->router);
        $pageModel->setUserHelper($userHelper);

        return $pageModel;
    }

    /**
     * @return RedirectModel
     */
    protected function getRedirectModel()
    {
        $urlHelper = $this
            ->getMockBuilder(UrlHelper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockRedirectModel = $this->getMockBuilder('Mautic\PageBundle\Model\RedirectModel')
            ->setConstructorArgs([$urlHelper])
            ->setMethods(['createRedirectEntity', 'generateRedirectUrl'])
            ->getMock();

        $mockRedirect = $this->getMockBuilder('Mautic\PageBundle\Entity\Redirect')
            ->getMock();

        $mockRedirectModel->expects($this->any())
            ->method('createRedirectEntity')
            ->willReturn($mockRedirect);

        $mockRedirectModel->expects($this->any())
            ->method('generateRedirectUrl')
            ->willReturn('http://some-url.com');

        return $mockRedirectModel;
    }
}
