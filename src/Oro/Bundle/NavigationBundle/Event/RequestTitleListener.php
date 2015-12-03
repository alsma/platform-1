<?php

namespace Oro\Bundle\NavigationBundle\Event;

use Oro\Bundle\NavigationBundle\Provider\TitleServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\HttpKernel;

class RequestTitleListener
{
    /** @var TitleServiceInterface */
    private $titleService;

    /**
     * @param TitleServiceInterface $titleService
     */
    public function __construct(TitleServiceInterface $titleService)
    {
        $this->titleService = $titleService;
    }

    /**
     * Find title for current route in database
     *
     * @param GetResponseEvent $event
     */
    public function onKernelRequest(GetResponseEvent $event)
    {
        $request = $event->getRequest();
        if (HttpKernel::MASTER_REQUEST != $event->getRequestType()
            || $request->getRequestFormat() != 'html'
            || ($request->getMethod() != 'GET'
                && !$request->headers->get(ResponseHashnavListener::HASH_NAVIGATION_HEADER))
            || ($request->isXmlHttpRequest()
                && !$request->headers->get(ResponseHashnavListener::HASH_NAVIGATION_HEADER))
        ) {
            // don't do anything
            return;
        }

        $route = $request->get('_route');

        $this->titleService->loadByRoute($route);
    }
}
