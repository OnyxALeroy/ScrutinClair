<?php

namespace App\Controller;

use App\Repository\PersonnalitePolitiqueRepository;
use App\Repository\VoteRepository;
use App\Service\DeputePhotoCache;
use App\Service\PropositionLoiIndex;
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
        PropositionLoiIndex $propositionLoiIndex,
    ): Response {
        $personnalite = $personnaliteRepository->findOneBy(['uid' => $uid]);
        if ($personnalite === null) {
            throw $this->createNotFoundException('Cette personnalité politique est introuvable.');
        }

        $parPage = 30;
        $total = $voteRepository->compterPourPersonalite($personnalite);
        $pages = max(1, (int) ceil($total / $parPage));
        $page = min(max(1, (int) $request->query->get('page', 1)), $pages);

        return $this->render('personnalite/show.html.twig', [
            'personnalite' => $personnalite,
            'stats' => $voteRepository->statistiquesPourPersonalite($personnalite),
            'votes' => $voteRepository->findByPersonalite($personnalite, $parPage, ($page - 1) * $parPage),
            'propositions' => $propositionLoiIndex->propositionsPourAuteur($personnalite->getUid() ?? ''),
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
}
