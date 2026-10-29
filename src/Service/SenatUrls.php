<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SenatUrls
{
    private readonly string $site;
    private readonly string $openData;

    public function __construct(
        #[Autowire('%env(SENAT_URL)%')]
        string $domaine,
    ) {
        $domaine = preg_replace('#^https?://#i', '', trim($domaine));
        $domaine = preg_replace('#^www\.#i', '', $domaine);
        $this->site = 'https://www.' . $domaine;
        $this->openData = 'https://data.' . $domaine;
    }

    public function senateursZipUrl(): string
    {
        return $this->openData . '/data/senateurs/export_sens.zip';
    }

    public function sessionUrl(int $annee): string
    {
        return sprintf('%s/scrutin-public/scr%d.html', $this->site, $annee);
    }

    public function scrutinHtmlUrl(int $annee, int $numero): string
    {
        return sprintf('%s/scrutin-public/%d/scr%d-%d.html', $this->site, $annee, $annee, $numero);
    }

    public function scrutinJsonUrl(int $annee, int $numero): string
    {
        return sprintf('%s/scrutin-public/%d/scr%d-%d.json', $this->site, $annee, $annee, $numero);
    }

    public function photoUrl(string $nom, string $prenom, string $senmat): string
    {
        return sprintf(
            '%s/senimg/%s_%s%s_carre.jpg',
            $this->site,
            $this->slug($nom),
            $this->slug($prenom),
            strtolower($senmat),
        );
    }

    public function dossierUrl(string $dossierRef): string
    {
        return sprintf('%s/dossier-legislatif/%s.html', $this->site, $dossierRef);
    }

    private function slug(string $valeur): string
    {
        $valeur = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valeur) ?: $valeur;
        $valeur = strtolower($valeur);
        $valeur = (string) preg_replace('/[^a-z0-9]+/', '_', $valeur);

        return trim($valeur, '_');
    }
}
