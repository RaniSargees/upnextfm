<?php
namespace AppBundle\Topic;

use AppBundle\Entity\Room;
use AppBundle\Entity\User;
use AppBundle\Entity\Video;
use AppBundle\Entity\VideoLog;
use AppBundle\Entity\VideoRepository;
use AppBundle\Entity\Vote;
use AppBundle\EventListener\Event\PlayedVideoEvent;
use AppBundle\EventListener\Event\UserEvents;
use AppBundle\Playlist\ProvidersInterface;
use AppBundle\Playlist\RngMod;
use AppBundle\Service\VideoInfo;
use AppBundle\Service\VideoService;
use AppBundle\Storage\PlaylistStorage;
use Gos\Bundle\WebSocketBundle\Router\WampRequest;
use Gos\Bundle\WebSocketBundle\Topic\TopicPeriodicTimerInterface;
use Gos\Bundle\WebSocketBundle\Topic\TopicPeriodicTimerTrait;
use Predis\Client as Redis;
use Ratchet\ConnectionInterface;
use Ratchet\Wamp\Topic;
use Symfony\Component\Security\Core\User\UserInterface;

class VideoTopic extends AbstractTopic implements TopicPeriodicTimerInterface
{
  use TopicPeriodicTimerTrait;

  /**
   * @var array
   */
  protected $subs = [];

  /**
   * @var Room[]
   */
  protected $rooms = [];

  /**
   * @var PlaylistStorage
   */
  protected $playlist;

  /**
   * @var RngMod
   */
  protected $rngmod;

  /**
   * @var Redis
   */
  protected $redis;

  /**
   * @var ProvidersInterface
   */
  protected $providers;

  /**
   * @var VideoService
   */
  protected $videoService;

  /**
   * @var VideoRepository
   */
  protected $videoRepo;

  /**
   * {@inheritdoc}
   */
  public function getName()
  {
    return "video.topic";
  }

  /**
   * @param PlaylistStorage $playlist
   * @return $this
   */
  public function setPlaylistStorage(PlaylistStorage $playlist)
  {
    $this->playlist = $playlist;
    return $this;
  }

  /**
   * @param RngMod $rngMod
   * @return $this
   */
  public function setRngMod(RngMod $rngMod)
  {
    $this->rngmod = $rngMod;
    return $this;
  }

  /**
   * @param ProvidersInterface $providers
   * @return $this
   */
  public function setProviders(ProvidersInterface $providers)
  {
    $this->providers = $providers;
    return $this;
  }

  /**
   * @param VideoService $videoService
   * @return $this
   */
  public function setVideoService(VideoService $videoService)
  {
    $this->videoService = $videoService;
    return $this;
  }

  /**
   * @param VideoRepository $videoRepo
   * @return $this
   */
  public function setVideoRepository(VideoRepository $videoRepo)
  {
    $this->videoRepo = $videoRepo;
    return $this;
  }

  /**
   * @param Redis $redis
   * @return $this
   */
  public function setRedis(Redis $redis)
  {
    $this->redis = $redis;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function onSubscribe(ConnectionInterface $conn, Topic $topic, WampRequest $request)
  {
    $user = $this->getUser($conn);
    if (!($user instanceof UserInterface)) {
      $user = null;
    }
    $room = $this->getRoom($request->getAttributes()->get("room"), $user);
    if (!$room) {
      $this->logger->error("Room not found or created.");
      return;
    }

    $client = ["conn" => $conn, "id" => $topic->getId()];
    $roomName = $room->getName();
    if (!isset($this->subs[$roomName])) {
      $this->subs[$roomName] = [];
    }
    if (!empty($this->subs[$roomName])) {
      $index = array_search($client, $this->subs[$roomName]);
      if (false !== $index) {
        unset($this->subs[$roomName][$index]);
      }
    }
    $this->subs[$roomName][] = $client;
    $this->rooms[$roomName] = $room;

    $videos = [];
    foreach ($this->playlist->getAll($room) as $videoLog) {
      $videos[] = $this->serializeVideo($videoLog);
    }
    if ($videos) {
      $conn->event($topic->getId(), [
        "cmd"    => VideoCommands::VIDEOS,
        "videos" => $videos
      ]);
    }

    $current = $this->playlist->getCurrent($room);
    if ($current) {
      /** @var VideoLog $videoLog */
      $videoLog = $current["videoLog"];
      if ($videoLog) {
        $conn->event($topic->getId(), [
          "cmd"   => VideoCommands::START,
          "start" => time() - $current["timeStarted"],
          "video" => $this->serializeVideo($videoLog)
        ]);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onUnSubscribe(ConnectionInterface $connection, Topic $topic, WampRequest $request)
  {
    $user = $this->getUser($connection);
    if (!($user instanceof UserInterface)) {
      $user = null;
    }
    $room = $this->getRoom($request->getAttributes()->get("room"), $user);
    if (!$room) {
      $this->logger->error("Room not found or created.");
      return;
    }

    $roomName = $room->getName();
    if (!isset($this->subs[$roomName])) {
      $this->subs[$roomName] = [];
    }
    if (!empty($this->subs[$roomName])) {
      $client = ["conn" => $connection, "id" => $topic->getId()];
      $index = array_search($client, $this->subs[$roomName]);
      if (false !== $index) {
        unset($this->subs[$roomName][$index]);
        if (count($this->subs[$roomName]) === 0) {
          unset($this->subs[$roomName]);
          unset($this->rooms[$roomName]);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onPublish(
    ConnectionInterface $conn,
    Topic $topic,
    WampRequest $req,
    $event,
    array $exclude,
    array $eligible
  )
  {

    try {
      $event = array_map("trim", $event);
      if (empty($event["cmd"])) {
        $this->logger->error("cmd not set.", $event);
        return;
      }
      $user = $this->getUser($conn);
      if (!($user instanceof UserInterface)) {
        $this->logger->error("User not found.", $event);
        return;
      }
      $room = $this->getRoom($req->getAttributes()->get("room"), $user);
      if (!$room || $room->getIsDeleted()) {
        $this->logger->error("Room not found.", $event);
        return;
      }

      switch ($event["cmd"]) {
        case VideoCommands::UPVOTE:
          $this->handleUpvote($conn, $topic, $req, $room, $user, $event);
          break;
        case VideoCommands::APPEND:
          $this->handleAppend($conn, $topic, $req, $room, $user, $event);
          break;
        case VideoCommands::REMOVE:
          $this->handleRemove($conn, $topic, $req, $room, $user, $event);
          break;
        case VideoCommands::PLAYNEXT:
          $this->handlePlayNext($conn, $topic, $req, $room, $user, $event);
          break;
      }
    } catch (\Exception $e) {
      $this->logger->error($e->getMessage());
    }
  }

  /**
   * @param ConnectionInterface $conn
   * @param Topic $topic
   * @param WampRequest $req
   * @param Room $room
   * @param UserInterface|User $user
   * @param array $event
   * @return mixed|void
   */
  protected function handleAppend(
    ConnectionInterface $conn,
    Topic $topic,
    WampRequest $req,
    Room $room,
    UserInterface $user,
    array $event
  )
  {

    $parsed = $this->providers->parseURL($event["url"]);
    if (!$parsed) {
      return $this->connSendError(
        $conn,
        $topic,
        "Invalid URL \"${event['url']}\"."
      );
    }

    if ($parsed["playlist"]) {
      $codenames = $this->videoService->getPlaylist($parsed["codename"], $parsed["provider"]);
      foreach ($codenames as $codename) {
        $video = $this->getOrCreateVideo(
          $room,
          $user,
          $codename,
          $parsed["provider"]
        );
        if ($video) {
          /** @var VideoLog $videoLog */
          $video->setDateLastPlayed(new \DateTime());
          $video->incrNumPlays();
          $videoLog = new VideoLog($video, $room, $user);
          $videoLog = $this->em->merge($videoLog);
          $this->em->flush();

          $this->playlist->append($videoLog);
          $event = new PlayedVideoEvent($user, $room, $video);
          $this->eventDispatcher->dispatch(UserEvents::PLAYED_VIDEO, $event);
        }
      }
      usleep(500);
    } else {
      $video = $this->getOrCreateVideo(
        $room,
        $user,
        $parsed["codename"],
        $parsed["provider"]
      );
      if (!$video) {
        return true;
      }

      /** @var VideoLog $videoLog */
      $video->setDateLastPlayed(new \DateTime());
      $video->incrNumPlays();
      $videoLog = new VideoLog($video, $room, $user);
      $videoLog = $this->em->merge($videoLog);
      $this->em->flush();

      $this->playlist->append($videoLog);
      $event = new PlayedVideoEvent($user, $room, $video);
      $this->eventDispatcher->dispatch(UserEvents::PLAYED_VIDEO, $event);
      usleep(500);
    }

    return $this->sendPlaylistToRoom($room);
  }

  /**
   * @param ConnectionInterface $conn
   * @param Topic $topic
   * @param WampRequest $req
   * @param Room $room
   * @param UserInterface|User $user
   * @param array $event
   * @return mixed|void
   */
  protected function handleRemove(
    ConnectionInterface $conn,
    Topic $topic,
    WampRequest $req,
    Room $room,
    UserInterface $user,
    array $event
  )
  {

    if (empty($event["videoID"])) {
      return $this->connSendError(
        $conn,
        $topic,
        "Invalid command."
      );
    }

    $result = $this->playlist->removeByID($room, $event["videoID"]);
    usleep(500);
    if (is_array($result)) {
      $videoLog = $result["videoLog"];
      $this->sendToRoom($room, [
        "cmd"   => VideoCommands::START,
        "video" => $this->serializeVideo($videoLog),
        "start" => 0
      ]);
    }

    return $this->sendPlaylistToRoom($room);
  }


  /**
   * @param ConnectionInterface $conn
   * @param Topic $topic
   * @param WampRequest $req
   * @param Room $room
   * @param UserInterface|User $user
   * @param array $event
   * @return mixed|void
   */
  protected function handleUpvote(
    ConnectionInterface $conn,
    Topic $topic,
    WampRequest $req,
    Room $room,
    UserInterface $user,
    array $event
  )
  {

    if (empty($event["videoID"])) {
      return $this->connSendError(
        $conn,
        $topic,
        "Invalid video ID in the handleUpvote method."
      );
    }

    $videoLog = $this->em->getRepository("AppBundle:VideoLog")
                ->findByID($event["videoID"]);

    $video = $videoLog->getVideo();

    $vote = new Vote();
    $vote->setValue(1);
    $vote->setVideo($video);
    $vote->setUser($user);

    $this->em->persist($vote);
    $this->em->flush();

    return $this->sendPlaylistToRoom($room);
  }

  /**
   * @param ConnectionInterface $conn
   * @param Topic $topic
   * @param WampRequest $req
   * @param Room $room
   * @param UserInterface|User $user
   * @param array $event
   * @return mixed|void
   */
  protected function handlePlayNext(
    ConnectionInterface $conn,
    Topic $topic,
    WampRequest $req,
    Room $room,
    UserInterface $user,
    array $event
  )
  {

    if (empty($event["videoID"])) {
      return $this->connSendError(
        $conn,
        $topic,
        "Invalid command."
      );
    }

    $this->playlist->playNext($room, $event["videoID"]);
    usleep(500);

    return $this->sendPlaylistToRoom($room);
  }

  /**
   * @param Topic $topic
   *
   * @return mixed
   */
  public function registerPeriodicTimer(Topic $topic)
  {
    $this->periodicTimer->addPeriodicTimer(
      $this,
      VideoCommands::TIME_UPDATE,
      $this->container->getParameter("app_ws_video_time_update_interval"),
      function () use ($topic) {

        /** @var VideoLog $videoLog */
        foreach ($this->rooms as $roomName => $room) {
          $current = $this->playlist->getCurrent($room);
          if (!$current) {
            $current = $this->playlist->popToCurrent($room);
          }

          if ($current) {
            $videoLog      = $current["videoLog"];
            $videoSecs     = $videoLog->getVideo()->getSeconds();
            $timeFinishes  = $current["timeStarted"] + $videoSecs;
            $timeRemaining = $timeFinishes - time();

            $this->logger->debug(sprintf(
              'Current for room "%s" is "%s" with time remaining %s.',
              $roomName,
              $videoLog->getVideo()->getTitle(),
              $timeRemaining
            ));

            if ($timeRemaining <= 0) {
              if ($current = $this->playlist->popToCurrent($room)) {
                $videoLog = $current["videoLog"];
                $this->logger->info(sprintf(
                  'Current set to "%s" for room "%s".',
                  $videoLog->getVideo()->getTitle(),
                  $roomName
                ));

                $this->sendToRoom($room, [
                  "cmd"   => VideoCommands::START,
                  "video" => $this->serializeVideo($videoLog),
                  "start" => 0
                ]);
                $this->sendPlaylistToRoom($room);
              } else {
                $this->playlist->clearCurrent($room);
                $this->sendToRoom($room, [
                  "cmd" => VideoCommands::STOP
                ]);
                $this->sendPlaylistToRoom($room);
              }
            } else {
              $this->sendToRoom($room, [
                "cmd"  => VideoCommands::TIME_UPDATE,
                "time" => $videoSecs - $timeRemaining
              ]);
            }
          } else {
            if ($logs = $this->rngmod->findByRoom($room, 3)) {
              foreach ($logs as $videoLog) {
                $this->playlist->append($videoLog);
                $event = new PlayedVideoEvent($videoLog->getUser(), $room, $videoLog->getVideo());
                $this->eventDispatcher->dispatch(UserEvents::PLAYED_VIDEO, $event);
              }
            }

            $this->sendPlaylistToRoom($room);
          }
        }
      }
    );

    $this->periodicTimer->addPeriodicTimer(
      $this,
      "append",
      1,
      function () use ($topic) {
        $item = $this->redis->lpop("playlist:append");
        if ($item) {
          $item = json_decode($item, true);
          $user = $this->em->getRepository("AppBundle:User")->findByUsername($item["username"]);
          $room = $this->em->getRepository("AppBundle:Room")->findByName($item["roomName"]);
          $video = $this->em->getRepository("AppBundle:Video")->findByID($item["videoID"]);

          /** @var VideoLog $videoLog */
          $video->setDateLastPlayed(new \DateTime());
          $video->incrNumPlays();
          $videoLog = new VideoLog($video, $room, $user);
          $videoLog = $this->em->merge($videoLog);
          $this->em->flush();

          $this->playlist->append($videoLog);
          $event = new PlayedVideoEvent($user, $room, $videoLog->getVideo());
          $this->eventDispatcher->dispatch(UserEvents::PLAYED_VIDEO, $event);
          usleep(500);

          $this->sendPlaylistToRoom($room);
        }
      }
    );
  }

  /**
   * @param Room $room
   * @param UserInterface|User $user
   * @param string $codename
   * @param string $provider
   * @return Video|null
   */
  private function getOrCreateVideo(Room $room, UserInterface $user, $codename, $provider)
  {
    $video = $this->videoRepo->findByCodename($codename, $provider);
    if (!$video) {
      $info = $this->videoService->getInfo($codename, $provider);
      if (!$info) {
        return null;
      }

      $video = new Video();
      $video->setCodename($info->getCodename());
      $video->setProvider($info->getProvider());
      $video->setCreatedByUser($user);
      $video->setCreatedInRoom($room);
      $video->setTitle($info->getTitle());
      $video->setSeconds($info->getSeconds());
      $video->setPermalink($info->getPermalink());
      $video->setThumbColor($info->getThumbColor());
      $video->setThumbSm($info->getThumbnail("sm"));
      $video->setThumbMd($info->getThumbnail("md"));
      $video->setThumbLg($info->getThumbnail("lg"));
      $video->setNumPlays(0);
      $this->em->persist($video);
    }

    return $video;
  }

  /**
   * @param Room $room
   * @param mixed $msg
   */
  private function sendToRoom(Room $room, $msg)
  {
    $roomName = $room->getName();
    foreach ($this->subs[$roomName] as $client) {
      $client["conn"]->event($client["id"], $msg);
    }
  }

  /**
   * @param Room $room
   * @param bool $playOnEmpty
   * @return bool
   */
  private function sendPlaylistToRoom(Room $room, $playOnEmpty = true)
  {
    if ($playOnEmpty) {
      if (!$this->playlist->getCurrent($room)) {
        if ($current = $this->playlist->popToCurrent($room)) {
          $videoLog = $current["videoLog"];
          $this->sendToRoom($room, [
            "cmd"   => VideoCommands::START,
            "video" => $this->serializeVideo($videoLog),
            "start" => 0
          ]);
        }
      }
    }

    $videos = [];
    foreach ($this->playlist->getAll($room) as $videoLog) {
      $videos[] = $this->serializeVideo($videoLog);
    }
    $this->sendToRoom($room, [
      "cmd"    => VideoCommands::VIDEOS,
      "videos" => $videos
    ]);

    return true;
  }
}
