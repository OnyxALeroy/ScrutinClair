<?php

namespace App\Controller;

use App\Entity\Tag;
use App\Entity\Texte;
use App\Repository\GroupePolitiqueRepository;
use App\Repository\PersonnalitePolitiqueRepository;
use App\Repository\TexteRepository;
use App\Service\TitreCourtGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(private readonly TitreCourtGenerator $titreCourt)
    {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(
        Request $request,
        TexteRepository $texteRepository,
        GroupePolitiqueRepository $groupeRepository,
        PersonnalitePolitiqueRepository $personnaliteRepository,
    ): Response {
        $q = trim((string) $request->query->get('q', ''));

        return $this->render('home/index.html.twig', [
            'q' => $q,
            'textes' => $q === '' ? [] : $texteRepository->search($q, 20),
            'groupes' => $q === '' ? [] : $groupeRepository->search($q, 10),
            'personnalites' => $q === '' ? [] : $personnaliteRepository->search($q, 12),
        ]);
    }

    #[Route('/api/recherche', name: 'app_recherche', methods: ['GET'])]
    public function recherche(
        Request $request,
        TexteRepository $texteRepository,
        GroupePolitiqueRepository $groupeRepository,
        PersonnalitePolitiqueRepository $personnaliteRepository,
    ): JsonResponse {
        $q = trim((string) $request->query->get('q', ''));
        if ($q === '') {
            return $this->json([]);
        }

        $items = [];

        foreach ($groupeRepository->search($q, 5) as $groupe) {
            $items[] = [
                'type' => 'parti',
                'label' => $groupe->getLibelleCourt(),
                'sub' => $groupe->getNom(),
                'url' => $this->generateUrl('app_partis_show', ['uid' => $groupe->getUid()]),
            ];
        }

        foreach ($personnaliteRepository->search($q, 5) as $personnalite) {
            $items[] = [
                'type' => 'personnalite',
                'label' => $personnalite->getNomComplet(),
                'sub' => $personnalite->getGroupePolitique()?->getLibelleCourt(),
                'url' => $this->generateUrl('app_personnalites_show', ['uid' => $personnalite->getUid()]),
            ];
        }

        foreach ($texteRepository->search($q, 10) as $texte) {
            $items[] = [
                'type' => 'texte',
                'label' => $this->titreAffiche($texte),
                'sub' => $texte->getSynthese(),
                'url' => $this->generateUrl('app_texte_show', ['id' => $texte->getId()]),
            ];
        }

        return $this->json($items);
    }

    #[Route('/api/textes', name: 'app_textes_search', methods: ['GET'])]
    public function searchTextes(Request $request, TexteRepository $texteRepository): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));

        $textes = array_map(fn (Texte $texte): array => [
            'id' => $texte->getId(),
            'titre' => $this->titreAffiche($texte),
            'titreLong' => $texte->getTitreLong(),
            'synthese' => $texte->getSynthese(),
            'themes' => array_map(static fn (Tag $tag): array => [
                'label' => $tag->getNom(),
                'slug' => $tag->getSlug(),
                'couleur' => $tag->getCouleur(),
            ], $texte->getThemes()->toArray()),
        ], $texteRepository->search($q));

        return $this->json($textes);
    }

    #[Route('/textes/{id}', name: 'app_texte_show', methods: ['GET'])]
    public function show(Texte $texte): Response
    {
        return $this->render('texte/show.html.twig', [
            'texte' => $texte,
        ]);
    }

    private function titreAffiche(Texte $texte): string
    {
        $court = trim((string) $texte->getTitreCourt());
        if ($court !== '') {
            return $court;
        }

        return $this->titreCourt->genererSansIa($texte->getTitreLong()) ?? $texte->getTitreLong();
    }
}
