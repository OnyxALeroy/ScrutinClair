<?php

namespace App\Controller;

use App\Repository\PersonnalitePolitiqueRepository;
use App\Repository\TexteRepository;
use App\Repository\VoteRepository;
use App\Service\DeputePhotoCache;
use App\Service\TextesInitiativesIndex;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PersonnalitePolitiqueController extends AbstractController
{
    #[Route('/personnalites', name: 'app_personnalites', methods: ['GET'])]
    public function index(Request $request, PersonnalitePolitiqueRepository $personnaliteRepository): Response
    {
        $q = trim((string) $request->query->get('q', ''));

        return $this->render('personnalite/index.html.twig', [
            'q' => $q,
            'personnalites' => $q === ''
                ? $personnaliteRepository->findAllOrdered()
                : $personnaliteRepository->search($q, 60),
        ]);
    }

    #[Route('/personnalites/{uid}', name: 'app_personnalites_show', methods: ['GET'], requirements: ['uid' => '[A-Za-z0-9]+'])]
    public function show(
        string $uid,
        Request $request,
        PersonnalitePolitiqueRepository $personnaliteRepository,
        VoteRepository $voteRepository,
        TexteRepository $texteRepository,
        TextesInitiativesIndex $textesInitiativesIndex,
    ): Response {
        $personnalite = $personnaliteRepository->findOneBy(['uid' => $uid]);
        if ($personnalite === null) {
            throw $this->createNotFoundException('Cette personnalité politique est introuvable.');
        }

        $parPage = 30;
        $total = $voteRepository->compterPourPersonalite($personnalite);

        $themesVotes = $this->grouperParTheme($voteRepository->findVotesTaguesParPersonalite($personnalite));

        $totalSansTag = $voteRepository->compterSansTagPourPersonalite($personnalite);
        $pages = max(1, (int) ceil($totalSansTag / $parPage));
        $page = min(max(1, (int) $request->query->get('page', 1)), $pages);

        $propositions = $textesInitiativesIndex->propositionsPourAuteur($personnalite->getUid() ?? '');
        $projets = $textesInitiativesIndex->projetsPourAuteur($personnalite->getUid() ?? '');
        $texteIdsParDossier = $texteRepository->idsRepresentatifsParDossierRef(
            array_column([...$propositions, ...$projets], 'dossierRef'),
        );

        return $this->render('personnalite/show.html.twig', [
            'personnalite' => $personnalite,
            'stats' => $voteRepository->statistiquesPourPersonalite($personnalite),
            'themesVotes' => $themesVotes,
            'votesSansTag' => $voteRepository->findSansTagPourPersonalite($personnalite, $parPage, ($page - 1) * $parPage),
            'totalSansTag' => $totalSansTag,
            'propositions' => $propositions,
            'projets' => $projets,
            'texteIdsParDossier' => $texteIdsParDossier,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    #[Route('/personnalites/{uid}/photo', name: 'app_personnalites_photo', methods: ['GET'], requirements: ['uid' => '[A-Za-z0-9]+'])]
    public function photo(string $uid, DeputePhotoCache $photoCache): Response
    {
        $path = $photoCache->ensure($uid);
        if ($path === null) {
            throw $this->createNotFoundException('Photo introuvable pour cette personnalité.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'image/jpeg');
        $response->setPublic();
        $response->setMaxAge(604800);

        return $response;
    }

    /**
     * @param list<array{voteId: int, statut: string, texteId: int, objet: ?string, titre: ?string, titreCourt: ?string, tagId: int, tagNom: string, tagSlug: string, tagCouleur: ?string}> $lignes
     *
     * @return list<array{tag: array{id: int, nom: string, slug: string, couleur: ?string}, stats: array{pour: int, contre: int, abstention: int, total: int}, votes: list<array{voteId: int, statut: string, texteId: int, objet: ?string, titre: ?string, titreCourt: ?string}>}>
     */
    private function grouperParTheme(array $lignes): array
    {
        $groupes = [];

        foreach ($lignes as $ligne) {
            $id = $ligne['tagId'];

            if (!isset($groupes[$id])) {
                $groupes[$id] = [
                    'tag' => [
                        'id' => $id,
                        'nom' => $ligne['tagNom'],
                        'slug' => $ligne['tagSlug'],
                        'couleur' => $ligne['tagCouleur'],
                    ],
                    'stats' => ['pour' => 0, 'contre' => 0, 'abstention' => 0, 'total' => 0],
                    'votes' => [],
                ];
            }

            $statut = strtolower($ligne['statut']);
            if (isset($groupes[$id]['stats'][$statut])) {
                ++$groupes[$id]['stats'][$statut];
            }

            ++$groupes[$id]['stats']['total'];
            $groupes[$id]['votes'][] = [
                'voteId' => $ligne['voteId'],
                'statut' => $ligne['statut'],
                'texteId' => $ligne['texteId'],
                'objet' => $ligne['objet'],
                'titre' => $ligne['titre'],
                'titreCourt' => $ligne['titreCourt'],
            ];
        }

        usort($groupes, static fn (array $a, array $b): int => $b['stats']['total'] <=> $a['stats']['total']);

        return $groupes;
    }
}
