<?php
namespace AppBundle\Entity;

use Doctrine\ORM\EntityRepository;

class UserRepository extends EntityRepository
{
  /**
   * Returns the user with the given username
   *
   * @param string $username
   *
   * @return User
   */
  public function findByUsername($username)
  {
    return $this->findOneBy([
      "username" => $username
    ]);
  }

  /**
   * Returns the user with the given email
   *
   * @param string $email
   *
   * @return User
   */
  public function findByEmail($email)
  {
    return $this->findOneBy([
      "email" => $email
    ]);
  }
}