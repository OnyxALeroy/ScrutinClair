<?php

namespace App\Service;

use App\Entity\Texte;
use App\Entity\VulgarisationDossier;
use App\Repository\VulgarisationDossierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Orchestre la production d'une vulgarisation pour un scrutin :
 *   - votes sur un texte de loi → vulgarisation du texte du dossier ;
 *   - scrutins sur amendement → vulgarisation du texte de loi (mutualisée par
 *     dossier) puis vulgarisation du seul amendement, concaténées.
 */
final class VulgarisationGenerator
{
    public function __construct(
        private readonly DossierLegislatifIndex $dossiers,
        private readonly AmendementResolver $amendements,
        private readonly Vulgarisateur $vulgarisateur,
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
     * @return array{contenu: string, source: string}|null null si aucune source exploitable
     */
    public function generer(Texte $texte, ?callable $onEtape = null): ?array
    {
        $source = $this->resoudre($texte);
        if ($source === null) {
            return null;
        }

        // 1. Vulgarisation du texte de loi (mutualisée par dossier).
        $vulgLoi = null;
        if ($source['dossierRef'] !== null) {
            $vulgLoi = $this->vulgarisationDossier($source['dossierRef'], $onEtape);
        }

        // 2. Scrutin sur amendement : on ajoute la vulgarisation de l'amendement.
        if ($source['type'] === 'amendement' && $source['amendement'] !== null) {
            if ($onEtape !== null) {
                $onEtape('amendement', 1, 1);
            }

            $vulgAmendement = $this->vulgarisateur->vulgariserAmendement($source['amendement']['contenu']);

            return [
                'contenu' => implode("\n\n", array_filter([$vulgLoi, $vulgAmendement])),
                'source' => $source['source'],
            ];
        }

        if ($vulgLoi === null) {
            return null;
        }

        return ['contenu' => $vulgLoi, 'source' => $source['source']];
    }

    /**
     * @param callable(string, int, int): void|null $onEtape
     */
    private function vulgarisationDossier(string $dossierRef, ?callable $onEtape): ?string
    {
        $cache = $this->vulgarisationDossierRepository->findOneBy(['dossierRef' => $dossierRef]);
        if ($cache !== null && $cache->getContenu() !== null) {
            return $cache->getContenu();
        }

        $uids = $this->dossiers->texteUidsForDossier($dossierRef);
        if ($uids === []) {
            return null;
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
            return null;
        }

        $vulgarisation = $this->vulgarisateur->vulgariserTexteLoi(
            $contenu,
            $onEtape === null ? null : static fn (int $courant, int $total) => $onEtape('loi', $courant, $total),
        );

        $cache = new VulgarisationDossier();
        $cache->setDossierRef($dossierRef);
        $cache->setContenu($vulgarisation);
        $cache->setModele($this->vulgarisateur->modele());
        $this->entityManager->persist($cache);
        $this->entityManager->flush();

        return $vulgarisation;
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
