<?php

namespace App\Service;

use App\Entity\Texte;
use App\Entity\VulgarisationDossier;
use App\Enum\Theme;
use App\Repository\VulgarisationDossierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Orchestre la production d'une vulgarisation pour un scrutin :
 *   - votes sur un texte de loi → vulgarisation du texte du dossier ;
 *   - scrutins sur amendement → vulgarisation du texte de loi (mutualisée par
 *     dossier) puis vulgarisation du seul amendement, concaténées.
 *
 * Les thèmes politiques sont attribués en même temps : le dossier est classé
 * à partir de sa synthèse, l'amendement à partir de son texte brut, et le
 * texte porte l'union des deux.
 */
final class VulgarisationGenerator
{
    public function __construct(
        private readonly DossierLegislatifIndex $dossiers,
        private readonly AmendementResolver $amendements,
        private readonly Vulgarisateur $vulgarisateur,
        private readonly ThemeClassifier $classifieur,
        private readonly HtmlTextExtractor $htmlExtractor,
        private readonly VulgarisationDossierRepository $vulgarisationDossierRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AssembleeUrls $urls,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Résout la source exploitable pour un scrutin, sans appeler l'IA.
     *
     * @return array{type: string, source: string, dossierRef: ?string, uid: ?string, amendement: ?array}|null
     */
    public function resoudre(Texte $texte): ?array
    {
        if ($this->estScrutinAmendement($texte)) {
            $amendement = $this->amendements->resolve($texte);
            if ($amendement !== null && trim($amendement['contenu']) !== '') {
                return [
                    'type' => 'amendement',
                    'source' => $amendement['amendementUid'],
                    'dossierRef' => $amendement['dossierRef'],
                    'uid' => $amendement['texteUid'],
                    'amendement' => $amendement,
                ];
            }
        }

        $dossierRef = $texte->getDossierRef();
        if ($dossierRef !== null && $dossierRef !== '') {
            $uid = $this->dossiers->texteUidForDossier($dossierRef);
            if ($uid !== null) {
                return [
                    'type' => 'dossier',
                    'source' => 'dossier:' . $dossierRef,
                    'dossierRef' => $dossierRef,
                    'uid' => $uid,
                    'amendement' => null,
                ];
            }
        }

        return null;
    }

    /**
     * @param callable(string, int, int): void|null $onEtape
     *
     * @return array{contenu: string, source: string, themes: list<Theme>}|null null si aucune source exploitable
     */
    public function generer(Texte $texte, ?callable $onEtape = null): ?array
    {
        $source = $this->resoudre($texte);
        if ($source === null) {
            return null;
        }

        // 1. Vulgarisation du texte de loi (mutualisée par dossier).
        $vulgLoi = null;
        $themes = [];
        if ($source['dossierRef'] !== null) {
            $dossier = $this->vulgarisationDossier($source['dossierRef'], $onEtape);
            $vulgLoi = $dossier['contenu'];
            $themes = $dossier['themes'];
        }

        // 2. Scrutin sur amendement : on ajoute la vulgarisation de l'amendement.
        if ($source['type'] === 'amendement' && $source['amendement'] !== null) {
            if ($onEtape !== null) {
                $onEtape('amendement', 1, 1);
            }

            $contenuAmendement = $source['amendement']['contenu'];
            $vulgAmendement = $this->vulgarisateur->vulgariserAmendement($contenuAmendement);

            // L'amendement est classé sur son texte brut, plus signalétique
            // que la synthèse du dossier.
            $themes = $this->fusionner($themes, $this->classifieur->classer($contenuAmendement));

            return [
                'contenu' => implode("\n\n", array_filter([$vulgLoi, $vulgAmendement])),
                'source' => $source['source'],
                'themes' => $themes,
            ];
        }

        if ($vulgLoi === null) {
            return null;
        }

        return ['contenu' => $vulgLoi, 'source' => $source['source'], 'themes' => $themes];
    }

    /**
     * Vulgarisation et thèmes d'un dossier, mutualisés par dossier.
     *
     * @param callable(string, int, int): void|null $onEtape
     *
     * @return array{contenu: ?string, themes: list<Theme>}
     */
    private function vulgarisationDossier(string $dossierRef, ?callable $onEtape): array
    {
        $cache = $this->vulgarisationDossierRepository->findOneBy(['dossierRef' => $dossierRef]);
        if ($cache !== null && $cache->getContenu() !== null) {
            return [
                'contenu' => $cache->getContenu(),
                'themes' => $this->themesDepuisCache($cache),
            ];
        }

        $uids = $this->dossiers->texteUidsForDossier($dossierRef);
        if ($uids === []) {
            return ['contenu' => null, 'themes' => []];
        }

        $contenu = null;
        foreach ($uids as $uid) {
            $contenu = $this->fetchDocumentText($uid);
            if ($contenu !== null && trim($contenu) !== '') {
                break;
            }
            $contenu = null;
        }

        if ($contenu === null) {
            return ['contenu' => null, 'themes' => []];
        }

        $vulgarisation = $this->vulgarisateur->vulgariserTexteLoi(
            $contenu,
            $onEtape === null ? null : static fn (int $courant, int $total) => $onEtape('loi', $courant, $total),
        );

        // Le dossier est classé sur sa synthèse : c'est elle qui résume le
        // contenu réel du texte de loi.
        $themes = $this->classifieur->classer($vulgarisation);

        $cache = new VulgarisationDossier();
        $cache->setDossierRef($dossierRef);
        $cache->setContenu($vulgarisation);
        $cache->setModele($this->vulgarisateur->modele());
        $cache->setThemes($this->libelles($themes));
        $this->entityManager->persist($cache);
        $this->entityManager->flush();

        return ['contenu' => $vulgarisation, 'themes' => $themes];
    }

    /**
     * Thèmes d'un dossier déjà vulgarisé : ils sont lus dans le cache, et
     * recalculés si le dossier a été vulgarisé avant l'existence des thèmes.
     *
     * @return list<Theme>
     */
    private function themesDepuisCache(VulgarisationDossier $cache): array
    {
        $themes = [];
        foreach ($cache->getThemes() ?? [] as $libelle) {
            $theme = Theme::fromLabel($libelle);
            if ($theme !== null) {
                $themes[] = $theme;
            }
        }

        if ($themes !== []) {
            return $themes;
        }

        $contenu = $cache->getContenu();
        if ($contenu === null || trim($contenu) === '') {
            return [];
        }

        $themes = $this->classifieur->classer($contenu);
        if ($themes !== []) {
            $cache->setThemes($this->libelles($themes));
            $this->entityManager->flush();
        }

        return $themes;
    }

    /**
     * @param list<Theme> $themes
     *
     * @return list<string>
     */
    private function libelles(array $themes): array
    {
        return array_map(static fn (Theme $theme): string => $theme->label(), $themes);
    }

    /**
     * @param list<Theme> $themes
     * @param list<Theme> $autres
     *
     * @return list<Theme>
     */
    private function fusionner(array $themes, array $autres): array
    {
        $fusion = [];
        foreach ([...$themes, ...$autres] as $theme) {
            $fusion[$theme->name] = $theme;
        }

        return array_values($fusion);
    }

    private function fetchDocumentText(string $uid): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $this->urls->documentHtmlUrl($uid), [
                'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; ScrutinClair/1.0)'],
                'timeout' => 60,
                'http_version' => '1.1',
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            return $this->htmlExtractor->extract($response->getContent(false));
        } catch (\Throwable) {
            return null;
        }
    }

    private function estScrutinAmendement(Texte $texte): bool
    {
        $libelle = mb_strtolower($texte->getObjet() ?? $texte->getTitre() ?? '');

        return str_contains($libelle, 'amendement') || str_contains($libelle, 'sous-amendement');
    }
}
