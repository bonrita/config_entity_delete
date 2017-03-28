<?php

namespace Drupal\brt_paragraph_delete\EventSubscriber;


use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ParagraphDelete implements EventSubscriberInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $currentRouteMatch;

  /**
   * Constructs a new MenuBlockKernelViewSubscriber.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $current_route_match
   *   The current route match.
   */
  public function __construct(RouteMatchInterface $current_route_match) {
    $this->currentRouteMatch = $current_route_match;
  }

  /**
   * @inheritDoc
   */
  public static function getSubscribedEvents() {
    // Run before main_content_view_subscriber.
    $events[KernelEvents::VIEW][] = ['onView', 1];
    return $events;
  }

  /**
   * Alters the block library modal.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The event to process.
   */
  public function onView(GetResponseEvent $event) {

    switch ($this->currentRouteMatch->getRouteName()){
      case 'entity.paragraphs_type.delete_form':

        $request = \Drupal::requestStack()->getCurrentRequest();

        if ($request->attributes->has('paragraphs_type') && !$request->query->has('delete')) {
          /** @var \Drupal\paragraphs\Entity\ParagraphsType $paragraph_type */
          $paragraph_type = $request->attributes->get('paragraphs_type', FALSE);

          $urlGenerator = \Drupal::service('url_generator');
          $options['absolute'] = TRUE;
          $route_parameters = [
            'paragraph_type' => $paragraph_type->id(),
          ];

          $url = $urlGenerator->generateFromRoute('brt_paragraph_delete.multiple_delete_confirm', $route_parameters, $options);

          $response = new RedirectResponse($url, 302);
          // Redirect an authenticated user to the profile form.
          $event->setResponse($response);
        }

        break;
    }
  }


}
