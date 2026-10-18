<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use ZipArchive;

/**
 * Indexe le jeu de données « Dossiers législatifs » de l'Assemblée pour
 * retrouver, à partir de l'uid d'un député (auteur), les propositions de loi
 * qu'il ou elle a déposées.
 */
final class PropositionLoiIndex
{
    /** @var array<string, list<array{uid: string, titre: string, dossierRef: ?string, dateDepot: ?string, lien: string}>>|null */
    private ?array $propositionsByAuteur = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly AssembleeUrls $urls,
        private readonly ResilientDownloader $downloader,
    ) {
    }

    /**
     * @return list<array{uid: string, titre: string, dossierRef: ?string, dateDepot: ?string, lien: string}>
     */
    public function propositionsPourAuteur(string $acteurUid): array
    {
        $this->ensureIndex();

        return $this->propositionsByAuteur[$acteurUid] ?? [];
    }

    public function compterPourAuteur(string $acteurUid): int
    {
        return \count($this->propositionsPourAuteur($acteurUid));
    }

    private function ensureIndex(): void
    {
        if ($this->propositionsByAuteur !== null) {
            return;
        }

        $this->propositionsByAuteur = [];

        $dataDir = $this->projectDir . '/data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }

        $zipPath = $dataDir . '/dossiers.zip';
        if (!is_file($zipPath) || filesize($zipPath) === 0) {
            $this->downloader->download($this->urls->dossiersZipUrl(), $zipPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Impossible d\'ouvrir l\'archive des dossiers législatifs.');
        }

        $legislature = (string) $this->urls->legislature();
        $meilleurParDossier = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_starts_with($name, 'json/document/PIONAN') || !str_ends_with($name, '.json')) {
                continue;
            }

            $raw = $zip->getFromIndex($i);
            if ($raw === false) {
                continue;
            }

            $data = json_decode($raw, true);
            $document = $data['document'] ?? null;
            if (!\is_array($document)) {
                continue;
            }

            if ((string) ($document['legislature'] ?? '') !== $legislature) {
                continue;
            }

            $dossierRef = $document['dossierRef'] ?? null;
            $uid = $document['uid'] ?? null;
            if (!\is_string($uid) || !\is_string($dossierRef) || $dossierRef === '') {
                continue;
            }

            $provenance = $document['provenance'] ?? null;
            $depot = $document['cycleDeVie']['chrono']['dateDepot'] ?? null;

            $entree = [
                'uid' => $uid,
                'titre' => (string) ($document['titres']['titrePrincipalCourt'] ?? $document['titres']['titrePrincipal'] ?? ''),
                'dossierRef' => $dossierRef,
                'dateDepot' => \is_string($depot) ? substr($depot, 0, 10) : null,
                'provenance' => \is_string($provenance) ? $provenance : null,
                'auteurs' => $this->extraireAuteurs($document['auteurs'] ?? []),
            ];

            $existant = $meilleurParDossier[$dossierRef] ?? null;
            if ($existant === null || $this->estMeilleur($entree, $existant)) {
                $meilleurParDossier[$dossierRef] = $entree;
            }
        }

        $zip->close();

        foreach ($meilleurParDossier as $entree) {
            $proposition = [
                'uid' => $entree['uid'],
                'titre' => $entree['titre'],
                'dossierRef' => $entree['dossierRef'],
                'dateDepot' => $entree['dateDepot'],
                'lien' => $this->urls->documentHtmlUrl($entree['uid']),
            ];

            foreach ($entree['auteurs'] as $acteurUid) {
                $this->propositionsByAuteur[$acteurUid][] = $proposition;
            }
        }

        foreach ($this->propositionsByAuteur as &$propositions) {
            usort($propositions, static fn (array $a, array $b): int => strcmp((string) $b['dateDepot'], (string) $a['dateDepot']));
        }
        unset($propositions);
    }

    /**
     * @param array<string, mixed> $auteurs
     *
     * @return list<string>
     */
    private function extraireAuteurs(array $auteurs): array
    {
        $auteur = $auteurs['auteur'] ?? [];
        if (!\is_array($auteur)) {
            return [];
        }
        if (isset($auteur['acteur'])) {
            $auteur = [$auteur];
        }

        $uids = [];
        foreach ($auteur as $entree) {
            $ref = $entree['acteur']['acteurRef'] ?? null;
            if (\is_string($ref) && $ref !== '') {
                $uids[$ref] = true;
            }
        }

        return array_keys($uids);
    }

    /**
     * @param array<string, mixed> $candidat
     * @param array<string, mixed> $existant
     */
    private function estMeilleur(array $candidat, array $existant): bool
    {
        $candidatDepose = 'Texte Déposé' === ($candidat['provenance'] ?? '');
        $existantDepose = 'Texte Déposé' === ($existant['provenance'] ?? '');

        if ($candidatDepose !== $existantDepose) {
            return $candidatDepose;
        }

        return (string) ($candidat['dateDepot'] ?? '') < (string) ($existant['dateDepot'] ?? '');
    }
}
