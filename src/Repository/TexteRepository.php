<?php

namespace App\Repository;

use App\Entity\Tag;
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
    public function findPourVulgarisation(bool $force = false, ?int $limit = null, ?string $uid = null, ?string $type = null, ?int $shard = null, ?int $nbShards = null): array
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

        // Répartition stable entre processus parallèles : chaque texte tombe
        // dans un seul morceau (répartition modulo l'identifiant).
        if ($shard !== null && $nbShards !== null && $nbShards > 0) {
            $qb->andWhere('MOD(t.id, :nbShards) = :shard')
                ->setParameter('nbShards', $nbShards)
                ->setParameter('shard', $shard);
        }

        if ($limit !== null && $limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Textes restant à classer : ceux qui ne portent encore aucun thème.
     *
     * @return list<Texte>
     */
    public function findPourTaguage(bool $force = false, ?int $limit = null, ?string $uid = null, ?int $shard = null, ?int $nbShards = null): array
    {
        $qb = $this->createQueryBuilder('t')->orderBy('t.id', 'ASC');

        if (!$force) {
            $qb->leftJoin('t.themes', 'tag')->andWhere('tag.id IS NULL');
        }

        if ($uid !== null && $uid !== '') {
            $qb->andWhere('t.uidScrutin = :uid')->setParameter('uid', $uid);
        }

        if ($shard !== null && $nbShards !== null && $nbShards > 0) {
            $qb->andWhere('MOD(t.id, :nbShards) = :shard')
                ->setParameter('nbShards', $nbShards)
                ->setParameter('shard', $shard);
        }

        if ($limit !== null && $limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    public function compterTotal(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
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
     * Textes portant un thème, paginés (du plus récent au plus ancien).
     *
     * @return list<Texte>
     */
    public function findPourTheme(Tag $tag, int $limite, int $offset): array
    {
        /** @var list<Texte> $textes */
        $textes = $this->createQueryBuilder('t')
            ->innerJoin('t.themes', 'theme')
            ->andWhere('theme = :theme')
            ->setParameter('theme', $tag)
            ->orderBy('t.id', 'DESC')
            ->setMaxResults($limite)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $textes;
    }

    public function compterPourTheme(Tag $tag): int
    {
        return (int) $this->createQueryBuilder('t')
            ->innerJoin('t.themes', 'theme')
            ->andWhere('theme = :theme')
            ->setParameter('theme', $tag)
            ->select('COUNT(t.id)')
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
