<?php

namespace App\Repository;

use App\Entity\GroupePolitique;
use App\Entity\PersonnalitePolitique;
use App\Entity\Vote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Vote>
 */
class VoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vote::class);
    }

    /**
     * Nombre de textes distincts sur lesquels un groupe s'est prononcé.
     */
    public function compterTextesPourGroupe(GroupePolitique $groupe): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(DISTINCT t.id)')
            ->join('v.texte', 't')
            ->andWhere('v.groupePolitique = :groupe')
            ->setParameter('groupe', $groupe)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Votes d'un groupe agrégés par texte, du plus récent au plus ancien.
     *
     * @return list<array{texteId: int, objet: ?string, titre: ?string, titreCourt: ?string, pour: int, contre: int, abstention: int, total: int}>
     */
    public function statistiquesParTextePourGroupe(GroupePolitique $groupe, int $limit = 20, int $offset = 0): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('t.id AS texteId', 't.objet AS objet', 't.titre AS titre', 't.titreCourt AS titreCourt')
            ->addSelect("SUM(CASE WHEN v.statut = 'Pour' THEN 1 ELSE 0 END) AS pour")
            ->addSelect("SUM(CASE WHEN v.statut = 'Contre' THEN 1 ELSE 0 END) AS contre")
            ->addSelect("SUM(CASE WHEN v.statut = 'Abstention' THEN 1 ELSE 0 END) AS abstention")
            ->addSelect('COUNT(v.id) AS total')
            ->join('v.texte', 't')
            ->andWhere('v.groupePolitique = :groupe')
            ->setParameter('groupe', $groupe)
            ->groupBy('t.id')
            ->addGroupBy('t.objet')
            ->addGroupBy('t.titre')
            ->addGroupBy('t.titreCourt')
            ->orderBy('t.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'texteId' => (int) $row['texteId'],
            'objet' => $row['objet'],
            'titre' => $row['titre'],
            'titreCourt' => $row['titreCourt'],
            'pour' => (int) $row['pour'],
            'contre' => (int) $row['contre'],
            'abstention' => (int) $row['abstention'],
            'total' => (int) $row['total'],
        ], $rows);
    }

    /**
     * Nombre de votes exprimés par une personnalité.
     */
    public function compterPourPersonalite(PersonnalitePolitique $personalite): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.personalite = :personalite')
            ->setParameter('personalite', $personalite)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Décompte global des votes d'une personnalité.
     *
     * @return array{pour: int, contre: int, abstention: int, total: int}
     */
    public function statistiquesPourPersonalite(PersonnalitePolitique $personalite): array
    {
        $row = $this->createQueryBuilder('v')
            ->select("SUM(CASE WHEN v.statut = 'Pour' THEN 1 ELSE 0 END) AS pour")
            ->addSelect("SUM(CASE WHEN v.statut = 'Contre' THEN 1 ELSE 0 END) AS contre")
            ->addSelect("SUM(CASE WHEN v.statut = 'Abstention' THEN 1 ELSE 0 END) AS abstention")
            ->addSelect('COUNT(v.id) AS total')
            ->andWhere('v.personalite = :personalite')
            ->setParameter('personalite', $personalite)
            ->getQuery()
            ->getSingleResult();

        return [
            'pour' => (int) $row['pour'],
            'contre' => (int) $row['contre'],
            'abstention' => (int) $row['abstention'],
            'total' => (int) $row['total'],
        ];
    }

    /**
     * Votes d'une personnalité, avec le texte associé, du plus récent au plus ancien.
     *
     * @return Vote[]
     */
    public function findByPersonalite(PersonnalitePolitique $personalite, int $limit = 30, int $offset = 0): array
    {
        return $this->createQueryBuilder('v')
            ->addSelect('t')
            ->join('v.texte', 't')
            ->andWhere('v.personalite = :personalite')
            ->setParameter('personalite', $personalite)
            ->orderBy('t.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
