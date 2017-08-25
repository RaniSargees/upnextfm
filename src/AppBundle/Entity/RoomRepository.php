<?php
namespace AppBundle\Entity;

class RoomRepository extends AbstractRepository
{
  /**
   * Returns the room with the given name
   *
   * @param string $name
   *
   * @return Room
   */
  public function findByName($name)
  {
    return $this->findOneBy([
      "name" => $name
    ]);
  }

  /**
   * @param int $limit
   * @return Room[]
   */
  public function findPublic($limit)
  {
    return $this->createQueryBuilder("r")
      ->where("r.isDeleted = 0")
      ->andWhere("r.isPrivate = 0")
      ->setMaxResults($limit)
      ->getQuery()
      ->execute();
  }
}
