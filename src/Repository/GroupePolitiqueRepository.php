<?php

namespace App\Repository;

use App\Entity\GroupePolitique;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupePolitique>
 */
class GroupePolitiqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupePolitique::class);
    }

    /**
     * Recherche par nom ou acronyme.
     *
     * @return GroupePolitique[]
     */
    public function search(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        return $this->createQueryBuilder('g')
            ->andWhere('LOWER(g.nom) LIKE LOWER(:q) OR LOWER(g.acronyme) LIKE LOWER(:q)')
            ->setParameter('q', '%' . $q . '%')
            ->orderBy('g.ordre', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Tous les groupes, de la gauche vers la droite de l'hémicycle.
     *
     * @return GroupePolitique[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('g')
            ->orderBy('g.ordre', 'ASC')
            ->addOrderBy('g.acronyme', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
