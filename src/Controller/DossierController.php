<?php

namespace App\Controller;

use App\Repository\TexteRepository;
use App\Service\DossierLegislatifIndex;
use App\Service\TextesInitiativesIndex;
use App\Service\AssembleeUrls;
use App\Service\SenatUrls;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class DossierController extends AbstractController
{
    public function __construct(
        private readonly TexteRepository $textes,
        private readonly DossierLegislatifIndex $dossiers,
        private readonly TextesInitiativesIndex $initiatives,
        private readonly AssembleeUrls $assembleeUrls,
        private readonly SenatUrls $senatUrls,
    ) {
    }

    #[Route('/dossiers/{dossierRef}', name: 'app_dossier_show')]
    public function show(string $dossierRef): Response
    {
        $titre = $this->dossiers->dossierTitle($dossierRef) ?? null;

        // Pagination params (separate pages for AN and Sénat)
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $pageAn = max(1, (int) $request->query->get('page_an', 1));
        $pageSenat = max(1, (int) $request->query->get('page_senat', 1));
        $perPage = 10;

        $qbBase = $this->textes->createQueryBuilder('t')
            ->andWhere('t.dossierRef = :dossierRef')
            ->setParameter('dossierRef', $dossierRef)
            ->orderBy('t.dateScrutin', 'DESC')
            ->addOrderBy('t.id', 'DESC');

        // Assemblée
        $qbAn = (clone $qbBase)->andWhere("t.chambre = :an")->setParameter('an', \App\Enum\Chambre::AssembleeNationale->value);
        $totalAn = (int) $qbAn->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
        $textesAn = $qbAn->select('t')->setFirstResult(($pageAn - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        // Sénat
        $qbSenat = (clone $qbBase)->andWhere("t.chambre = :senat")->setParameter('senat', \App\Enum\Chambre::Senat->value);
        $totalSenat = (int) $qbSenat->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
        $textesSenat = $qbSenat->select('t')->setFirstResult(($pageSenat - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        // Initiatives / documents indexés par le service (Assemblée opendata)
        $initiatives = $this->initiatives->documentsForDossier($dossierRef);

        $assembleeUid = $this->dossiers->texteUidForDossier($dossierRef);
        $assembleeUrl = $assembleeUid !== null ? $this->assembleeUrls->documentHtmlUrl($assembleeUid) : null;
        $senatUrl = $this->senatUrls->dossierUrl($dossierRef);

        return $this->render('dossier/show.html.twig', [
            'dossierRef' => $dossierRef,
            'titre' => $titre,
            'textesAn' => $textesAn,
            'textesSenat' => $textesSenat,
            'totalAn' => $totalAn,
            'totalSenat' => $totalSenat,
            'pageAn' => $pageAn,
            'pageSenat' => $pageSenat,
            'perPage' => $perPage,
            'initiatives' => $initiatives,
            'assembleeUrl' => $assembleeUrl,
            'senatUrl' => $senatUrl,
        ]);
    }
}
