<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use ZipArchive;

/**
 * Indexe le jeu de données « Dossiers législatifs » de l'Assemblée
 * pour retrouver, à partir d'un dossierRef, l'uid du document de loi
 * dont on peut récupérer le texte intégral (via /dyn/opendata/{uid}.html).
 */
final class DossierLegislatifIndex
{
    /** @var array<string, list<array{uid: string, titre: ?string, score: int}>>|null */
    private ?array $documentsByDossier = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly AssembleeUrls $urls,
        private readonly ResilientDownloader $downloader,
    ) {
    }

    /**
     * @return list<array{uid: string, titre: ?string, score: int}>
     */
    public function documentsForDossier(string $dossierRef): array
    {
        $this->ensureIndex();

        return $this->documentsByDossier[$dossierRef] ?? [];
    }

    /**
     * uid du meilleur document (texte adopté sinon texte de loi déposé).
     */
    public function texteUidForDossier(string $dossierRef): ?string
    {
        return $this->texteUidsForDossier($dossierRef)[0] ?? null;
    }

    /**
     * uids des documents exploitables, du plus prioritaire au moins prioritaire.
     * Permet de retomber sur un autre document si le premier n'est pas
     * disponible en opendata (ex. texte du Sénat).
     *
     * @return string[]
     */
    public function texteUidsForDossier(string $dossierRef): array
    {
        $documents = $this->documentsForDossier($dossierRef);
        if ($documents === []) {
            return [];
        }

        usort($documents, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(static fn (array $d): string => $d['uid'], $documents);
    }

    public function dossierTitle(string $dossierRef): ?string
    {
        foreach ($this->documentsForDossier($dossierRef) as $document) {
            if ($document['titre'] !== null && $document['titre'] !== '') {
                return $document['titre'];
            }
        }

        return null;
    }

    private function ensureIndex(): void
    {
        if ($this->documentsByDossier !== null) {
            return;
        }

        $this->documentsByDossier = [];

        $dataDir = $this->projectDir . '/data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }

        $zipPath = $dataDir . '/dossiers.zip';
        $this->downloader->download($this->urls->dossiersZipUrl(), $zipPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Impossible d\'ouvrir l\'archive des dossiers législatifs.');
        }

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_starts_with($name, 'json/document/') || !str_ends_with($name, '.json')) {
                continue;
            }

            $raw = $zip->getFromIndex($i);
            if ($raw === false) {
                continue;
            }

            $data = json_decode($raw, true);
            $document = $data['document'] ?? null;
            if (!is_array($document)) {
                continue;
            }

            $uid = $document['uid'] ?? null;
            $dossierRef = $document['dossierRef'] ?? null;
            if (!\is_string($uid) || !is_string($dossierRef) || $dossierRef === '') {
                continue;
            }

            $score = $this->scoreDocument($uid);
            if ($score === 0) {
                continue;
            }

            $titre = $document['titres']['titrePrincipalCourt']
                ?? $document['titres']['titrePrincipal']
                ?? null;

            $this->documentsByDossier[$dossierRef][] = [
                'uid' => $document['uid'],
                'titre' => \is_string($titre) ? $titre : null,
                'score' => $score,
            ];
        }

        $zip->close();
    }

    /**
     * Classe les documents : le texte adopté (BTC) prime sur le texte déposé.
     */
    private function scoreDocument(string $uid): int
    {
        // On ne garde que les projets / propositions de loi de l'Assemblée
        // (l'opendata n'expose que les textes de l'AN ; on exclut Sénat,
        // rapports, études d'impact, avis du Conseil d'État, amendements…).
        if (preg_match('/^(?:PRJL|PION)ANR\d+/i', $uid) !== 1) {
            return 0;
        }

        return match (true) {
            preg_match('/BTC\d+$/', $uid) === 1 => 100,
            preg_match('/BTA\d+$/', $uid) === 1 => 90,
            default => 50,
        };
    }
}
