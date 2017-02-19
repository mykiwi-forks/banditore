<?php

namespace AppBundle\Repository;

/**
 * VersionRepository.
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class VersionRepository extends \Doctrine\ORM\EntityRepository
{
    /**
     * Find all versions available for the given user.
     * They'll be put in a RSS feed.
     *
     * @param int $userId
     *
     * @return array
     */
    public function findForUser($userId)
    {
        return $this->createQueryBuilder('v')
            ->select('v.id', 'v.tagName', 'v.createdAt', 'v.body', 'r.fullName', 'r.ownerAvatar')
            ->leftJoin('v.repo', 'r')
            ->leftJoin('r.stars', 's')
            ->leftJoin('s.user', 'u')
            ->where('s.user = :userId')->setParameter('userId', $userId)
            ->andWhere('v.createdAt >= u.createdAt')
            ->orderBy('v.createdAt', 'desc')
            ->setMaxResults(10)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * DQL query to retrieve last version of each repo starred by a user.
     * We use DQL because it was to complex to use a query builder.
     *
     * @param int $userId User ID
     *
     * @return array
     */
    public function findLastVersionForEachRepoForUser($userId)
    {
        $query = $this->getEntityManager()->createQuery("
            SELECT v1.tagName, v1.name, v1.createdAt, r.fullName, r.ownerAvatar
            FROM AppBundle\Entity\Version v1
            LEFT JOIN AppBundle\Entity\Version v2 WITH ( v1.repo = v2.repo AND v1.createdAt < v2.createdAt )
            LEFT JOIN AppBundle\Entity\Star s WITH s.repo = v1.repo
            LEFT JOIN AppBundle\Entity\Repo r WITH r.id = s.repo
            WHERE v2.repo IS NULL
            AND s.user = :userId
            ORDER BY v1.createdAt DESC");

        $query->setParameter('userId', $userId);

        return $query->getArrayResult();
    }
}
