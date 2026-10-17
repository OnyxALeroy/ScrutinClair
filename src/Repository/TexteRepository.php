<?php

namespace App\Repository;

use App\Entity\Texte;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Texte>
 */
class TexteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Texte::class);
    }

    /**
     * @return Texte[]
     */
    public function search(string $q, int $limit = 10): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->andWhere('LOWER(t.titre) LIKE LOWER(:q) OR LOWER(t.objet) LIKE LOWER(:q) OR LOWER(t.synthese) LIKE LOWER(:q)')
            ->setParameter('q', '%' . $q . '%')
            ->orderBy('t.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
