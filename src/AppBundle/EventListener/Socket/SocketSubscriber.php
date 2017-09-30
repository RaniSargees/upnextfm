<?php
namespace AppBundle\EventListener\Socket;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SocketSubscriber implements EventSubscriberInterface
{
  /**
   * @var VideoListener
   */
  protected $videoListener;

  /**
   * @var RoomListener
   */
  protected $roomListener;

  /**
   * @var PMListener
   */
  protected $pmListener;

  /**
   * @param VideoListener $videoListener
   * @return $this
   */
  public function setVideoListener(VideoListener $videoListener)
  {
    $this->videoListener = $videoListener;
    return $this;
  }

  /**
   * @param RoomListener $roomListener
   * @return $this
   */
  public function setRoomListener(RoomListener $roomListener)
  {
    $this->roomListener = $roomListener;
    return $this;
  }

  /**
   * @param PMListener $pmListener
   * @return $this
   */
  public function setPMListener(PMListener $pmListener)
  {
    $this->pmListener = $pmListener;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents()
  {
    return [
      SocketEvents::VIDEO_REQUEST => "onVideoRequest",
      SocketEvents::ROOM_REQUEST  => "onRoomRequest",
      SocketEvents::PM_REQUEST    => "onPMRequest"
    ];
  }

  /**
   * @param VideoRequestEvent $event
   */
  public function onVideoRequest(VideoRequestEvent $event)
  {
    $payload = $event->getPayload();
    $user    = $event->getUser();
    $room    = $event->getRoom();

    foreach($payload["dispatch"] as $action) {
      $method = ucwords($action["action"]);
      $method = "on${method}";
      $args   = array_merge([$user, $room], $action["args"]);
      call_user_func_array([$this->videoListener, $method], $args);
    }
  }

  /**
   * Handles the SocketEvents::ROOM_REQUEST event
   *
   * @param RoomRequestEvent $event
   */
  public function onRoomRequest(RoomRequestEvent $event)
  {
    $payload = $event->getPayload();
    $user    = $event->getUser();
    $room    = $event->getRoom();

    foreach($payload["dispatch"] as $action) {
      $method = ucwords($action["action"]);
      $method = "on${method}";
      $args   = array_merge([$user, $room], $action["args"]);
      call_user_func_array([$this->roomListener, $method], $args);
    }
  }

  /**
   * Handles the SocketEvents::PM_REQUEST event
   *
   * @param PMRequestEvent $event
   */
  public function onPMRequest(PMRequestEvent $event)
  {
    $payload = $event->getPayload();
    $user    = $event->getUser();

    foreach($payload["dispatch"] as $action) {
      $method = ucwords($action["action"]);
      $method = "on${method}";
      $args   = array_merge([$user], $action["args"]);
      call_user_func_array([$this->pmListener, $method], $args);
    }
  }
}
