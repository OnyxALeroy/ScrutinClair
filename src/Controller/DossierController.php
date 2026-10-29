<?php

namespace App\Controller;

use App\Repository\TexteRepository;
use App\Service\DossierLegislatifIndex;
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
        private readonly AssembleeUrls $assembleeUrls,
        private readonly SenatUrls $senatUrls,
    ) {
    }

    #[Route('/dossiers', name: 'app_dossiers_index')]
    public function index(): Response
    {
        $refs = $this->dossiers->allDossierRefs();
        sort($refs);

        return $this->render('dossier/index.html.twig', ['dossierRefs' => $refs]);
    }

    #[Route('/dossiers/{dossierRef}', name: 'app_dossier_show')]
    public function show(string $dossierRef, ?\Symfony\Component\HttpFoundation\Request $request = null): Response
    {
        $titre = $this->dossiers->dossierTitle($dossierRef) ?? null;

        // Pagination params (separate pages for AN and Sénat)
        if ($request === null) {
            $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
        }
        $pageAn = max(1, (int) $request->query->get('page_an', 1));
        $pageSenat = max(1, (int) $request->query->get('page_senat', 1));
        $perPage = 10;

        // UI filters / sorting
        $chambreFilter = $request->query->get('chambre', 'all'); // all | AN | SENAT
        $showAn = $chambreFilter !== 'SENAT';
        $showSenat = $chambreFilter !== 'AN';
        $sort = $request->query->get('sort', 'date_desc');

        $qbBase = $this->textes->createQueryBuilder('t')
            ->andWhere('t.dossierRef = :dossierRef')
            ->setParameter('dossierRef', $dossierRef);

        // Assemblée
        $textesAn = [];
        $totalAn = 0;
        if ($showAn) {
            $qbAn = (clone $qbBase)->andWhere("t.chambre = :an")->setParameter('an', \App\Enum\Chambre::AssembleeNationale->value);
            // apply sorting
            if ($sort === 'date_asc') {
                $qbAn->orderBy('t.dateScrutin', 'ASC')->addOrderBy('t.id', 'ASC');
            } elseif ($sort === 'title_asc') {
                $qbAn->orderBy('t.titre', 'ASC')->addOrderBy('t.id', 'ASC');
            } elseif ($sort === 'title_desc') {
                $qbAn->orderBy('t.titre', 'DESC')->addOrderBy('t.id', 'DESC');
            } else {
                $qbAn->orderBy('t.dateScrutin', 'DESC')->addOrderBy('t.id', 'DESC');
            }
            // remove ordering before counting to avoid SQL errors with GROUP/BY
            $qbAnCount = (clone $qbAn);
            $qbAnCount->resetDQLPart('orderBy');
            $totalAn = (int) $qbAnCount->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
            $textesAn = $qbAn->select('t')->setFirstResult(($pageAn - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();
        }

        // Sénat
        $textesSenat = [];
        $totalSenat = 0;
        if ($showSenat) {
            $qbSenat = (clone $qbBase)->andWhere("t.chambre = :senat")->setParameter('senat', \App\Enum\Chambre::Senat->value);
            if ($sort === 'date_asc') {
                $qbSenat->orderBy('t.dateScrutin', 'ASC')->addOrderBy('t.id', 'ASC');
            } elseif ($sort === 'title_asc') {
                $qbSenat->orderBy('t.titre', 'ASC')->addOrderBy('t.id', 'ASC');
            } elseif ($sort === 'title_desc') {
                $qbSenat->orderBy('t.titre', 'DESC')->addOrderBy('t.id', 'DESC');
            } else {
                $qbSenat->orderBy('t.dateScrutin', 'DESC')->addOrderBy('t.id', 'DESC');
            }
            $qbSenatCount = (clone $qbSenat);
            $qbSenatCount->resetDQLPart('orderBy');
            $totalSenat = (int) $qbSenatCount->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
            $textesSenat = $qbSenat->select('t')->setFirstResult(($pageSenat - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();
        }

        // Initiatives / documents indexés par le service (Assemblée opendata)
        $initiatives = $this->dossiers->documentsForDossier($dossierRef);

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
            'showAn' => $showAn,
            'showSenat' => $showSenat,
            'sort' => $sort,
            'chambreFilter' => $chambreFilter,
            'initiatives' => $initiatives,
            'assembleeUrl' => $assembleeUrl,
            'senatUrl' => $senatUrl,
            'senatUrls' => $this->senatUrls,
        ]);
    }
}
