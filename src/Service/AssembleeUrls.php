<?php
namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AssembleeUrls
{
    private readonly string $site;
    private readonly string $openData;

    public function __construct(
        #[Autowire('%env(SITE_ASSEMBLEE_URL)%')]
        string $domain,
        #[Autowire('%env(int:LEGISLATURE)%')]
        private readonly int $legislature,
    ) {
        $host = preg_replace('#^https?://#i', '', trim($domain));
        $host = preg_replace('#^(?:www|data)\.#i', '', $host);

        $this->site = 'https://www.' . $host;
        $this->openData = 'https://data.' . $host;
    }

    public function legislature(): int
    {
        return $this->legislature;
    }

    public function scrutinsZipUrl(): string
    {
        return sprintf(
            '%s/static/openData/repository/%d/loi/scrutins/Scrutins.json.zip',
            $this->openData,
            $this->legislature,
        );
    }

    public function acteursZipUrl(): string
    {
        return sprintf(
            '%s/static/openData/repository/%d/amo/deputes_actifs_mandats_actifs_organes/AMO10_deputes_actifs_mandats_actifs_organes.json.zip',
            $this->openData,
            $this->legislature,
        );
    }

    public function acteursGouvernementsZipUrl(): string
    {
        return sprintf(
            '%s/static/openData/repository/%d/amo/deputes_senateurs_ministres_legislature/AMO20_dep_sen_min_tous_mandats_et_organes.json.zip',
            $this->openData,
            $this->legislature,
        );
    }

    public function dossiersZipUrl(): string
    {
        return sprintf(
            '%s/static/openData/repository/%d/loi/dossiers_legislatifs/Dossiers_Legislatifs.json.zip',
            $this->openData,
            $this->legislature,
        );
    }

    public function amendementsZipUrl(): string
    {
        return sprintf(
            '%s/static/openData/repository/%d/loi/amendements_div_legis/Amendements.json.zip',
            $this->openData,
            $this->legislature,
        );
    }

    public function scrutinUrl(int|string $numero): string
    {
        return sprintf('%s/dyn/%d/scrutins/%s', $this->site, $this->legislature, $numero);
    }

    public function documentHtmlUrl(string $uid): string
    {
        return sprintf('%s/dyn/opendata/%s.html', $this->site, $uid);
    }

    public function photoUrl(string $uid): string
    {
        $numero = preg_replace('/\D/', '', $uid);

        return sprintf('%s/dyn/static/tribun/%d/photos/carre/%s.jpg', $this->site, $this->legislature, $numero);
    }

    public function amendementPageUrl(string $texteNumero, string $chambre, int|string $numero): string
    {
        return sprintf(
            '%s/dyn/%d/amendements/%s/%s/%s',
            $this->site,
            $this->legislature,
            rawurlencode($texteNumero),
            $chambre,
            $numero,
        );
    }
}
