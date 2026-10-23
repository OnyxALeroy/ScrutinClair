<?php

namespace App\Repository;

use App\Entity\Gouvernement;
use App\Entity\MembreGouvernement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MembreGouvernement>
 */
class MembreGouvernementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MembreGouvernement::class);
    }

    /**
     * Membres d'un gouvernement, dans l'ordre protocolaire.
     *
     * @return MembreGouvernement[]
     */
    public function findByGouvernementOrdered(Gouvernement $gouvernement): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('p')
            ->join('m.personnalite', 'p')
            ->andWhere('m.gouvernement = :gouvernement')
            ->setParameter('gouvernement', $gouvernement)
            ->orderBy('m.preseance', 'ASC')
            ->addOrderBy('m.role', 'ASC')
            ->addOrderBy('p.nom', 'ASC')
            ->addOrderBy('p.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
