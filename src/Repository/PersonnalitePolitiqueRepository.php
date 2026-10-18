<?php

namespace App\Repository;

use App\Entity\GroupePolitique;
use App\Entity\PersonnalitePolitique;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PersonnalitePolitique>
 */
class PersonnalitePolitiqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonnalitePolitique::class);
    }

    /**
     * Recherche par nom ou prénom.
     *
     * @return PersonnalitePolitique[]
     */
    public function search(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->andWhere('LOWER(p.nom) LIKE LOWER(:q) OR LOWER(p.prenom) LIKE LOWER(:q)')
            ->setParameter('q', '%' . $q . '%')
            ->orderBy('p.nom', 'ASC')
            ->addOrderBy('p.prenom', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les personnalités, triées par nom.
     *
     * @return PersonnalitePolitique[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.nom', 'ASC')
            ->addOrderBy('p.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Membres d'un groupe politique, triés par nom.
     *
     * @return PersonnalitePolitique[]
     */
    public function findByGroupe(GroupePolitique $groupe): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.groupePolitique = :groupe')
            ->setParameter('groupe', $groupe)
            ->orderBy('p.nom', 'ASC')
            ->addOrderBy('p.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
