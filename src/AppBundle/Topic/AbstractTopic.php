<?php
namespace AppBundle\Topic;

use AppBundle\Entity\PrivateMessage;
use AppBundle\Entity\RoomSettings;
use AppBundle\Entity\User;
use AppBundle\Entity\UserSettings;
use AppBundle\Entity\VideoLog;
use AppBundle\EventListener\Event\CreatedRoomEvent;
use AppBundle\EventListener\Event\UserEvents;
use AppBundle\Storage\RoomStorage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMException;
use Exception;
use Gos\Bundle\WebSocketBundle\Client\Auth\WebsocketAuthenticationProviderInterface;
use Gos\Bundle\WebSocketBundle\Client\ClientManipulatorInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Guard\JWTTokenAuthenticator;
use Gos\Bundle\WebSocketBundle\Topic\TopicInterface;
use Gos\Bundle\WebSocketBundle\Router\WampRequest;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Ratchet\Wamp\WampConnection;
use Ratchet\ConnectionInterface;
use Ratchet\Wamp\Topic;
use AppBundle\Entity\ChatLog;
use AppBundle\Entity\Room;
use Psr\Log\LoggerInterface;

abstract class AbstractTopic implements TopicInterface
{
  /**
   * @var ContainerInterface
   */
  protected $container;

  /**
   * @var array
   */
  protected $socketSettings = [];

  /**
   * @var EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * @var ClientManipulatorInterface
   */
  protected $clientManipulator;

  /**
   * @var JWTTokenAuthenticator
   */
  protected $tokenAuthenticator;

  /**
   * @var UserProviderInterface
   */
  protected $userProvider;

  /**
   * @var WebsocketAuthenticationProviderInterface
   */
  protected $authenticationProvider;

  /**
   * @var EntityManagerInterface
   */
  protected $em;

  /**
   * @var RoomStorage
   */
  protected $roomStorage;

  /**
   * @var LoggerInterface
   */
  protected $logger;

  /**
   * @param ContainerInterface $container
   * @param LoggerInterface $logger
   */
  public function __construct(ContainerInterface $container, LoggerInterface $logger)
  {
    $this->container              = $container;
    $this->socketSettings         = $container->getParameter("app_ws_settings");
    $this->eventDispatcher        = $container->get("event_dispatcher");
    $this->clientManipulator      = $container->get("gos_web_socket.websocket.client_manipulator");
    $this->tokenAuthenticator     = $container->get("lexik_jwt_authentication.security.guard.jwt_token_authenticator");
    $this->userProvider           = $container->get("fos_user.user_provider.username");
    $this->authenticationProvider = $container->get("gos_web_socket.websocket_authentification.provider");
    $this->em                     = $container->get("doctrine.orm.default_entity_manager");
    $this->logger                 = $logger;
  }

  /**
   * @param RoomStorage $roomStorage
   * @return $this
   */
  public function setRoomStorage(RoomStorage $roomStorage)
  {
    $this->roomStorage = $roomStorage;
    return $this;
  }

  /**
   * This will receive any Subscription requests for this topic.
   *
   * @param ConnectionInterface|WampConnection $conn
   * @param Topic $topic
   * @param WampRequest $request
   * @return void
   */
  public function onSubscribe(ConnectionInterface $conn, Topic $topic, WampRequest $request)
  {
/*    $topic->broadcast([
      'cmd' => Commands::JOIN,
      'msg' => $connection->resourceId . " has joined " . $topic->getId()
    ]);*/
  }

  /**
   * This will receive any UnSubscription requests for this topic.
   *
   * @param ConnectionInterface|WampConnection $connection
   * @param Topic $topic
   * @param WampRequest $request
   * @return void
   */
  public function onUnSubscribe(ConnectionInterface $connection, Topic $topic, WampRequest $request)
  {
/*    $topic->broadcast([
      'cmd' => Commands::LEAVE,
      'msg' => $connection->resourceId . " has left " . $topic->getId()
    ]);*/
  }

  /**
   * Authenticates the user
   *
   * @param ConnectionInterface $connection
   * @param string $token
   * @return \Symfony\Component\Security\Core\Authentication\Token\TokenInterface
   */
  protected function authenticate(ConnectionInterface $connection, $token)
  {
    $connection->WebSocket->request->getQuery()->set("token", $token);
    return $this->authenticationProvider->authenticate($connection);
  }

  /**
   * @param string $key
   * @return mixed
   */
  protected function getParameter($key)
  {
    return $this->container->getParameter($key);
  }

  /**
   * @param ConnectionInterface|User $connection
   * @param array $event
   * @return UserInterface
   */
  protected function getUser(ConnectionInterface $connection, array $event = [])
  {
    $user = $this->clientManipulator->getClient($connection);
    if ($user instanceof UserInterface) {
      $username = $user->getUsername();
      if ($username) {
        $user = $this->em->getRepository("AppBundle:User")
          ->findByUsername($user->getUsername());
      }
    }

    return $user;
  }

  /**
   * @param string $roomName
   * @param UserInterface|User $user
   * @return Room
   */
  protected function getRoom($roomName, UserInterface $user = null)
  {
    $repo = $this->em->getRepository("AppBundle:Room");
    $room = $repo->findByName($roomName);
    if (!$room && $user !== null) {
      $thumbService = $this->container->get("app.service.thumbs");

      $room     = new Room($roomName, $user);
      $settings = new RoomSettings();
      $settings->setRoom($room);
      $settings->setIsPublic(true);
      $settings->setJoinMessage("Welcome to ${roomName}.");
      $settings->setThumbSm($thumbService->getRoomThumb($room, $user, "sm"));
      $settings->setThumbMd($thumbService->getRoomThumb($room, $user, "md"));
      $settings->setThumbLg($thumbService->getRoomThumb($room, $user, "lg"));
      $room->setSettings($settings);
      $this->em->persist($room);
      $this->em->flush();

      $event = new CreatedRoomEvent($user, $room);
      $this->eventDispatcher->dispatch(UserEvents::CREATED_ROOM, $event);
    }

    return $room;
  }

  /**
   * @param RoomSettings $settings
   * @return array
   */
  protected function serializeRoomSettings(RoomSettings $settings)
  {
    return [
      "isPublic" => $settings->isPublic(),
      "thumbSm"  => $settings->getThumbSm(),
      "thumbMd"  => $settings->getThumbMd(),
      "thumbLg"  => $settings->getThumbLg()
    ];
  }

  /**
   * @param UserSettings $settings
   * @return array
   */
  protected function serializeUserSettings(UserSettings $settings)
  {
    return [
      "showNotices" => $settings->getShowNotices(),
      "textColor"   => $settings->getTextColor()
    ];
  }

  /**
   * @param UserInterface|User $user
   * @return array
   */
  protected function serializeUser(UserInterface $user)
  {
    $username = $user->getUsername();
    return [
      "username" => $username,
      "avatar"   => $user->getInfo()->getAvatarSm(),
      "profile"  => "https://upnext.fm/u/${username}",
      "roles"    => $user->getRoles()
    ];
  }

  /**
   * @param VideoLog $videoLog
   * @return array
   */
  protected function serializeVideo(VideoLog $videoLog)
  {
    $video = $videoLog->getVideo();

    return [
      "id"        => $videoLog->getId(),
      "codename"  => $video->getCodename(),
      "provider"  => $video->getProvider(),
      "permalink" => $video->getPermalink(),
      "thumbnail" => $video->getThumbSm(),
      "title"     => $video->getTitle(),
      "seconds"   => $video->getSeconds(),
      "playedBy"  => $videoLog->getUser()->getUsername(),
      "createdBy" => $video->getCreatedByUser()->getUsername()
    ];
  }

  /**
   * @param ChatLog $message
   * @param string $type
   * @return array
   */
  protected function serializeMessage(ChatLog $message, $type = "message")
  {
    return [
      "type"    => $type,
      "id"      => $message->getId(),
      "date"    => $message->getDateCreated()->format("D M d Y H:i:s O"),
      "from"    => $message->getUser()->getUsername(),
      "message" => $message->getMessage()
    ];
  }

  /**
   * @param ChatLog[] $messages
   * @return array
   */
  protected function serializeMessages($messages)
  {
    $serialized = [];
    foreach($messages as $message) {
      $serialized[] = $this->serializeMessage($message);
    }

    return $serialized;
  }

  /**
   * @param PrivateMessage $pm
   * @return array
   */
  protected function serializePrivateMessage(PrivateMessage $pm)
  {
    return [
      "id"      => $pm->getId(),
      "type"    => "message",
      "to"      => $pm->getToUser()->getUsername(),
      "from"    => $pm->getFromUser()->getUsername(),
      "date"    => $pm->getDateCreated()->format("D M d Y H:i:s O"),
      "message" => $pm->getMessage()
    ];
  }

  /**
   * @param string $message
   * @return string
   */
  protected function sanitizeMessage($message)
  {
    return trim(htmlspecialchars($message));
  }

  /**
   * @return bool
   * @throws \Doctrine\ORM\ORMException
   */
  protected function reopenEntityManager()
  {
    if (!$this->em->isOpen()) {
      $this->em = $this->em->create(
        $this->em->getConnection(),
        $this->em->getConfiguration()
      );
      return true;
    }

    return false;
  }

  /**
   * @param \Exception $e
   */
  protected function handleError(Exception $e)
  {
    $this->logger->error($e);
    if ($e instanceof ORMException) {
      if (stripos($e->getMessage(), 'closed') !== -1) {
        $this->reopenEntityManager();
      }
    }
  }

  /**
   * @param ConnectionInterface $conn
   * @param Topic $topic
   * @param string $error
   * @return mixed
   */
  protected function connSendError(ConnectionInterface $conn, Topic $topic, $error)
  {
    return $conn->event($topic->getId(), [
      "cmd"   => Commands::ERROR,
      "error" => $error
    ]);
  }
}
