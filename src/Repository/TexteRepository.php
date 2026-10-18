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

    /**
     * Sélectionne les textes à vulgariser.
     *
     * @param string|null $type 'amendement', 'dossier' ou null (tous)
     *
     * @return Texte[]
     */
    public function findPourVulgarisation(bool $force = false, ?int $limit = null, ?string $uid = null, ?string $type = null): array
    {
        $qb = $this->createQueryBuilder('t')->orderBy('t.id', 'ASC');

        if (!$force) {
            $qb->andWhere('t.vulgarisation IS NULL');
        }

        if ($uid !== null && $uid !== '') {
            $qb->andWhere('t.uidScrutin = :uid')->setParameter('uid', $uid);
        }

        if ($type === 'amendement') {
            $qb->andWhere("LOWER(t.objet) LIKE '%amendement%'");
        } elseif ($type === 'dossier') {
            $qb->andWhere("LOWER(t.objet) NOT LIKE '%amendement%'");
        }

        if ($limit !== null && $limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array<string, int>
     */
    public function compterParStatut(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.vulgarisationStatut AS statut, COUNT(t.id) AS nombre')
            ->groupBy('t.vulgarisationStatut')
            ->getQuery()
            ->getArrayResult();

        $compteurs = [];
        foreach ($rows as $row) {
            $compteurs[(string) ($row['statut'] ?? 'null')] = (int) $row['nombre'];
        }

        return $compteurs;
    }

    public function compterVulgarises(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.vulgarisation IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<int>
     */
    public function findAllIds(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.id')
            ->orderBy('t.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
}
