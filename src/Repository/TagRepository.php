<?php

namespace App\Repository;

use App\Entity\Tag;
use App\Enum\Theme;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    /**
     * Tags déjà vus dans le run en cours : évite de créer deux lignes pour
     * un même thème avant le flush.
     *
     * @var array<string, Tag>
     */
    private array $cache = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /**
     * Récupère (ou crée) la ligne correspondant à un thème du catalogue.
     */
    public function obtenirPour(Theme $theme): Tag
    {
        if (isset($this->cache[$theme->name])) {
            return $this->cache[$theme->name];
        }

        $tag = $this->findOneBy(['nom' => $theme->label()]);
        if (!$tag instanceof Tag) {
            $tag = new Tag();
            $tag->setNom($theme->label());
            $tag->setSlug($theme->slug());
            $tag->setCouleur($theme->couleur());
            $this->getEntityManager()->persist($tag);
        }

        return $this->cache[$theme->name] = $tag;
    }

    /**
     * Nombre de textes portant au moins un thème.
     */
    public function compterTextesTagues(): int
    {
        return (int) $this->createQueryBuilder('tag')
            ->innerJoin('tag.textes', 'texte')
            ->select('COUNT(DISTINCT texte.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Thèmes auxquels au moins un texte est rattaché.
     *
     * @return list<Tag>
     */
    public function findAvecTextes(): array
    {
        /** @var list<Tag> $tags */
        $tags = $this->createQueryBuilder('tag')
            ->innerJoin('tag.textes', 'texte')
            ->select('tag')
            ->groupBy('tag.id')
            ->orderBy('tag.nom', 'ASC')
            ->getQuery()
            ->getResult();

        return $tags;
    }

    /**
     * Nombre de textes par thème, du plus fourni au moins fourni.
     *
     * @return array<string, int> libellé du thème => nombre de textes
     */
    public function compterTextesParTheme(): array
    {
        $lignes = $this->createQueryBuilder('tag')
            ->select('tag.nom AS nom, COUNT(texte.id) AS nombre')
            ->leftJoin('tag.textes', 'texte')
            ->groupBy('tag.id')
            ->orderBy('nombre', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $compteurs = [];
        foreach ($lignes as $ligne) {
            $compteurs[$ligne['nom']] = (int) $ligne['nombre'];
        }

        return $compteurs;
    }
}
