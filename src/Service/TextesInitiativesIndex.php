<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use ZipArchive;

/**
 * Indexe le jeu de données « Dossiers législatifs » de l'Assemblée pour
 * retrouver les textes portés par une personnalité :
 * - les propositions de loi dont elle est l'auteure ;
 * - les projets de loi dont elle est l'initiatrice au nom du Gouvernement.
 */
final class TextesInitiativesIndex
{
    /** @var array<string, list<array{uid: string, titre: string, dossierRef: ?string, dateDepot: ?string, lien: string, qualite: 'auteur'|'cosignataire'}>>|null */
    private ?array $propositionsByAuteur = null;

    /** @var array<string, list<array{uid: string, titre: string, dossierRef: ?string, dateDepot: ?string, lien: string}>>|null */
    private ?array $projetsByAuteur = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly AssembleeUrls $urls,
        private readonly ResilientDownloader $downloader,
    ) {
    }

    /**
     * @return list<array{uid: string, titre: string, dossierRef: ?string, dateDepot: ?string, lien: string, qualite: 'auteur'|'cosignataire'}>
     */
    public function propositionsPourAuteur(string $acteurUid): array
    {
        $this->ensureIndex();

        return $this->propositionsByAuteur[$acteurUid] ?? [];
    }

    public function compterPropositionsPourAuteur(string $acteurUid): int
    {
        return \count($this->propositionsPourAuteur($acteurUid));
    }

    /**
     * @return list<array{uid: string, titre: string, dossierRef: ?string, dateDepot: ?string, lien: string}>
     */
    public function projetsPourAuteur(string $acteurUid): array
    {
        $this->ensureIndex();

        return $this->projetsByAuteur[$acteurUid] ?? [];
    }

    public function compterProjetsPourAuteur(string $acteurUid): int
    {
        return \count($this->projetsPourAuteur($acteurUid));
    }

    private function ensureIndex(): void
    {
        if ($this->propositionsByAuteur !== null) {
            return;
        }

        $this->propositionsByAuteur = [];
        $this->projetsByAuteur = [];

        $dataDir = $this->projectDir . '/data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }

        $zipPath = $dataDir . '/dossiers.zip';
        $zip = $this->ouvrirArchive($zipPath);

        $legislature = (string) $this->urls->legislature();
        $meilleurPion = [];
        $meilleurProjet = [];
        $initiateursParDossier = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_ends_with($name, '.json')) {
                continue;
            }

            $raw = $zip->getFromIndex($i);
            if ($raw === false) {
                continue;
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }

            if (str_starts_with($name, 'json/document/')) {
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

                $depot = $document['cycleDeVie']['chrono']['dateDepot'] ?? null;
                $entree = [
                    'uid' => $uid,
                    'titre' => (string) ($document['titres']['titrePrincipalCourt'] ?? $document['titres']['titrePrincipal'] ?? ''),
                    'dossierRef' => $dossierRef,
                    'dateDepot' => \is_string($depot) ? substr($depot, 0, 10) : null,
                    'provenance' => \is_string($document['provenance'] ?? null) ? $document['provenance'] : null,
                    'auteurs' => $this->extraireAuteurs($document['auteurs'] ?? []),
                    'cosignataires' => $this->extraireCosignataires($document['coSignataires'] ?? []),
                ];

                if (str_starts_with($name, 'json/document/PIONAN')) {
                    $existant = $meilleurPion[$dossierRef] ?? null;
                    if ($existant === null || $this->estMeilleur($entree, $existant)) {
                        $meilleurPion[$dossierRef] = $entree;
                    }
                } elseif ($this->codeClassification($document) === 'PRJLOI') {
                    $existant = $meilleurProjet[$dossierRef] ?? null;
                    if ($existant === null || $this->estMeilleur($entree, $existant)) {
                        $meilleurProjet[$dossierRef] = $entree;
                    }
                }

                continue;
            }

            if (str_starts_with($name, 'json/dossierParlementaire/')) {
                $dossier = $data['dossierParlementaire'] ?? null;
                if (!\is_array($dossier) || (string) ($dossier['legislature'] ?? '') !== $legislature) {
                    continue;
                }

                $dossierRef = $dossier['uid'] ?? null;
                if (!\is_string($dossierRef) || $dossierRef === '') {
                    continue;
                }

                $initiateurs = $this->extraireInitiateurs($dossier['initiateur'] ?? []);
                if ($initiateurs !== []) {
                    $initiateursParDossier[$dossierRef] = $initiateurs;
                }
            }
        }

        $zip->close();

        foreach ($meilleurPion as $entree) {
            $propositionAuteur = $this->creerTexte($entree);
            $propositionAuteur['qualite'] = 'auteur';

            foreach ($entree['auteurs'] as $acteurUid) {
                $this->propositionsByAuteur[$acteurUid][$propositionAuteur['uid']] = $propositionAuteur;
            }

            $propositionCosignee = $this->creerTexte($entree);
            $propositionCosignee['qualite'] = 'cosignataire';

            foreach ($entree['cosignataires'] as $acteurUid) {
                if (!isset($this->propositionsByAuteur[$acteurUid][$propositionCosignee['uid']])) {
                    $this->propositionsByAuteur[$acteurUid][$propositionCosignee['uid']] = $propositionCosignee;
                }
            }
        }

        foreach ($meilleurProjet as $dossierRef => $entree) {
            $projet = $this->creerTexte($entree);

            foreach ($initiateursParDossier[$dossierRef] ?? [] as $acteurUid) {
                $this->projetsByAuteur[$acteurUid][] = $projet;
            }
        }

        $this->trier($this->propositionsByAuteur);
        $this->trier($this->projetsByAuteur);
    }

    private function ouvrirArchive(string $zipPath): ZipArchive
    {
        $zip = new ZipArchive();
        if (is_file($zipPath) && filesize($zipPath) > 0 && $zip->open($zipPath) === true) {
            if ($zip->numFiles > 0) {
                return $zip;
            }
            $zip->close();
        }

        if (is_file($zipPath)) {
            @unlink($zipPath);
        }

        $this->downloader->download($this->urls->dossiersZipUrl(), $zipPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Impossible d\'ouvrir l\'archive des dossiers législatifs.');
        }

        return $zip;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function codeClassification(array $document): string
    {
        return (string) ($document['classification']['famille']['classe']['code'] ?? '');
    }

    /**
     * @param array{uid: string, titre: string, dossierRef: ?string, dateDepot: ?string, provenance: ?string} $entree
     *
     * @return array{uid: string, titre: string, dossierRef: ?string, dateDepot: ?string, lien: string}
     */
    private function creerTexte(array $entree): array
    {
        return [
            'uid' => $entree['uid'],
            'titre' => $entree['titre'],
            'dossierRef' => $entree['dossierRef'],
            'dateDepot' => $entree['dateDepot'],
            'lien' => $this->urls->documentHtmlUrl($entree['uid']),
        ];
    }

    /**
     * @param array<string, list<array{uid: string, titre: string, dossierRef: ?string, dateDepot: ?string, lien: string}>> $textes
     */
    private function trier(array &$textes): void
    {
        foreach ($textes as &$liste) {
            usort($liste, static fn (array $a, array $b): int => strcmp((string) $b['dateDepot'], (string) $a['dateDepot']));
        }
        unset($liste);
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
     * @param array<string, mixed> $cosignataires
     *
     * @return list<string>
     */
    private function extraireCosignataires(array $cosignataires): array
    {
        $cosignataire = $cosignataires['coSignataire'] ?? [];
        if (!\is_array($cosignataire)) {
            return [];
        }
        if (isset($cosignataire['acteur'])) {
            $cosignataire = [$cosignataire];
        }

        $uids = [];
        foreach ($cosignataire as $entree) {
            if (!\is_array($entree) || !empty($entree['dateRetraitCosignature'])) {
                continue;
            }

            $ref = $entree['acteur']['acteurRef'] ?? null;
            if (\is_string($ref) && $ref !== '') {
                $uids[$ref] = true;
            }
        }

        return array_keys($uids);
    }

    /**
     * @param array<string, mixed> $initiateur
     *
     * @return list<string>
     */
    private function extraireInitiateurs(array $initiateur): array
    {
        $acteurs = $initiateur['acteurs']['acteur'] ?? [];
        if (!\is_array($acteurs)) {
            return [];
        }
        if (isset($acteurs['acteurRef'])) {
            $acteurs = [$acteurs];
        }

        $uids = [];
        foreach ($acteurs as $acteur) {
            $ref = $acteur['acteurRef'] ?? null;
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
