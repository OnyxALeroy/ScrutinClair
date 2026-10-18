<?php

namespace App\Service;

use App\Entity\Texte;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ZipArchive;

/**
 * Résout, pour un scrutin portant sur un amendement, le contenu de cet
 * amendement et le dossier législatif auquel il se rattache.
 *
 * Chaîne de résolution :
 *   page du scrutin → lien vers l'amendement → page de l'amendement (uid AMANR…)
 *   → entrée correspondante dans l'archive open data des amendements.
 */
final class AmendementResolver
{
    /** @var array<string, int>|null  uid AMANR… => index dans l'archive */
    private ?array $indexParUid = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly AssembleeUrls $urls,
        private readonly ResilientDownloader $downloader,
        private readonly HtmlTextExtractor $htmlExtractor,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return array{amendementUid: string, dossierRef: ?string, texteUid: ?string, contenu: string}|null
     */
    public function resolve(Texte $texte): ?array
    {
        $numero = $this->numeroScrutin($texte->getUidScrutin());
        if ($numero === null) {
            return null;
        }

        $lien = $this->extraireLienAmendement($this->urls->scrutinUrl($numero));
        if ($lien === null) {
            return null;
        }

        $uid = $this->extraireUidAmendement(
            $this->urls->amendementPageUrl($lien['texte'], $lien['chambre'], $lien['numero'])
        );
        if ($uid === null) {
            return null;
        }

        return $this->chargerAmendement($uid);
    }

    /**
     * @return array{amendementUid: string, dossierRef: ?string, texteUid: ?string, contenu: string}|null
     */
    private function chargerAmendement(string $uid): ?array
    {
        $index = $this->index();
        if (!isset($index[$uid])) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($this->zipPath()) !== true) {
            return null;
        }

        $entryIndex = $index[$uid];
        $entryName = (string) $zip->getNameIndex($entryIndex);
        $raw = $zip->getFromIndex($entryIndex);
        $zip->close();

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        $amendement = $data['amendement'] ?? null;
        if (!\is_array($amendement)) {
            return null;
        }

        $contenuAuteur = $amendement['corps']['contenuAuteur'] ?? [];
        $morceaux = array_filter([
            isset($contenuAuteur['exposeSommaire']) ? $this->htmlExtractor->extract((string) $contenuAuteur['exposeSommaire']) : null,
            isset($contenuAuteur['dispositif']) ? $this->htmlExtractor->extract((string) $contenuAuteur['dispositif']) : null,
        ]);

        return [
            'amendementUid' => $uid,
            'dossierRef' => $this->dossierRefFromEntry($entryName),
            'texteUid' => \is_string($amendement['texteLegislatifRef'] ?? null) ? $amendement['texteLegislatifRef'] : null,
            'contenu' => implode("\n\n", $morceaux),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function index(): array
    {
        if ($this->indexParUid !== null) {
            return $this->indexParUid;
        }

        $this->indexParUid = [];

        $zip = new ZipArchive();
        if ($zip->open($this->zipPath()) !== true) {
            return $this->indexParUid;
        }

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_ends_with($name, '.json')) {
                continue;
            }

            $uid = basename($name, '.json');
            if (str_starts_with($uid, 'AMANR')) {
                $this->indexParUid[$uid] = $i;
            }
        }

        $zip->close();

        return $this->indexParUid;
    }

    /**
     * @return array{texte: string, chambre: string, numero: string}|null
     */
    private function extraireLienAmendement(string $url): ?array
    {
        $html = $this->fetch($url);
        if ($html === null) {
            return null;
        }

        if (preg_match('#/amendements/([^/"\s]+)/(AN|SN)/(\d+)#', $html, $m) !== 1) {
            return null;
        }

        return ['texte' => $m[1], 'chambre' => $m[2], 'numero' => $m[3]];
    }

    private function extraireUidAmendement(string $url): ?string
    {
        $html = $this->fetch($url);
        if ($html === null) {
            return null;
        }

        return preg_match('/\b(AMANR[0-9A-Z]+)\b/', $html, $m) === 1 ? $m[1] : null;
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; ScrutinClair/1.0)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ],
                'timeout' => 30,
                'http_version' => '1.1',
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            return $response->getContent(false);
        } catch (\Throwable) {
            return null;
        }
    }

    private function numeroScrutin(?string $uid): ?string
    {
        if ($uid === null) {
            return null;
        }

        return preg_match('/V(\d+)$/i', $uid, $m) === 1 ? $m[1] : null;
    }

    private function dossierRefFromEntry(string $entryName): ?string
    {
        // json/{dossierRef}/{texteUid}/{amendementUid}.json
        $parts = explode('/', $entryName);

        return $parts[1] ?? null;
    }

    private function zipPath(): string
    {
        $dataDir = $this->projectDir . '/data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }

        $zipPath = $dataDir . '/amendements.zip';
        if (!is_file($zipPath) || filesize($zipPath) === 0) {
            $this->downloader->download($this->urls->amendementsZipUrl(), $zipPath);
        }

        return $zipPath;
    }
}
