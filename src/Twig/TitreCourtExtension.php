<?php

namespace App\Twig;

use App\Service\TitreCourtGenerator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class TitreCourtExtension extends AbstractExtension
{
    public function __construct(private readonly TitreCourtGenerator $generateur)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('titre_court', $this->titreCourt(...)),
        ];
    }

    /**
     * Repli d'affichage : transforme un titre officiel en titre court par
     * règles quand aucune version générée par l'IA n'est disponible.
     */
    public function titreCourt(?string $titreOfficiel): string
    {
        $titreOfficiel ??= '';
        $titreCourt = $this->generateur->genererSansIa($titreOfficiel);

        return $titreCourt ?? $titreOfficiel;
    }
}
