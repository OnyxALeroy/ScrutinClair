<?php

namespace App\Tests\Controller;

use App\Service\DossierLegislatifIndex;
use App\Service\TextesInitiativesIndex;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DossierControllerTest extends KernelTestCase
{
    public function testShowDisplaysTextesAndLinks(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // create a texte with dossierRef
        $token = bin2hex(random_bytes(4));
        $texte = new \App\Entity\Texte();
        $texte->setTitre('Titre ' . $token);
        $texte->setObjet('Objet ' . $token);
        $texte->setDossierRef('DLR-TEST-' . $token);
        $em->persist($texte);
        $em->flush();
        // prepare a real DossierLegislatifIndex but inject a pre-filled index to avoid network
        $assembleeUrls = new \App\Service\AssembleeUrls('https://www.assemblee-nationale.fr', 17);
        $downloader = new \App\Service\ResilientDownloader(new \Symfony\Component\HttpClient\MockHttpClient());
        $dossierService = new \App\Service\DossierLegislatifIndex(sys_get_temp_dir(), $assembleeUrls, $downloader);
        $docs = ['DLR-TEST-' . $token => [['uid' => 'PRJLANR123', 'titre' => 'Doc 1', 'score' => 100, 'lien' => 'https://example.test/doc1']]];
        $ref = new \ReflectionProperty(\App\Service\DossierLegislatifIndex::class, 'documentsByDossier');
        $ref->setAccessible(true);
        $ref->setValue($dossierService, $docs);

        $assembleeUrls2 = new \App\Service\AssembleeUrls('https://www.assemblee-nationale.fr', 17);
        $downloader2 = new \App\Service\ResilientDownloader(new \Symfony\Component\HttpClient\MockHttpClient());
        $initiativesService = new \App\Service\TextesInitiativesIndex(sys_get_temp_dir(), $assembleeUrls2, $downloader2);
        $ref2 = new \ReflectionProperty(\App\Service\TextesInitiativesIndex::class, 'propositionsByAuteur');
        $ref2->setAccessible(true);
        $ref2->setValue($initiativesService, ['DLR-TEST-' . $token => [['uid' => 'PRJLANR123', 'titre' => 'Doc 1', 'lien' => 'https://example.test/doc1']]]);

        static::getContainer()->set(DossierLegislatifIndex::class, $dossierService);
        static::getContainer()->set(TextesInitiativesIndex::class, $initiativesService);

        $controller = new \App\Controller\DossierController(
            static::getContainer()->get(\App\Repository\TexteRepository::class),
            $dossierService,
            new \App\Service\AssembleeUrls('https://www.assemblee-nationale.fr', 17),
            new \App\Service\SenatUrls('senat.fr'),
        );

        // ensure the controller has a container for render() to work
        $c = new \ReflectionProperty(\Symfony\Bundle\FrameworkBundle\Controller\AbstractController::class, 'container');
        $c->setAccessible(true);
        $c->setValue($controller, static::getContainer());

        $req = new \Symfony\Component\HttpFoundation\Request();
        static::getContainer()->get('request_stack')->push($req);
        $response = $controller->show('DLR-TEST-' . $token, $req);
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\Response::class, $response);
        $content = (string) $response->getContent();
        self::assertStringContainsString('DLR-TEST-' . $token, $content);
        // the page should link to the created texte
        self::assertStringContainsString((string) $texte->getTitre(), $content);
        self::assertStringContainsString('Doc 1', $content);
    }

    public function testIndexListsDossiers(): void
    {
        self::bootKernel();
        $assembleeUrls = new \App\Service\AssembleeUrls('https://www.assemblee-nationale.fr', 17);
        $downloader = new \App\Service\ResilientDownloader(new \Symfony\Component\HttpClient\MockHttpClient());
        $dossierIndex = new \App\Service\DossierLegislatifIndex(sys_get_temp_dir(), $assembleeUrls, $downloader);
        $ref3 = new \ReflectionProperty(\App\Service\DossierLegislatifIndex::class, 'documentsByDossier');
        $ref3->setAccessible(true);
        $ref3->setValue($dossierIndex, ['DLR-A' => [], 'DLR-B' => []]);
        static::getContainer()->set(DossierLegislatifIndex::class, $dossierIndex);

        $assembleeUrls2 = new \App\Service\AssembleeUrls('https://www.assemblee-nationale.fr', 17);
        $downloader2 = new \App\Service\ResilientDownloader(new \Symfony\Component\HttpClient\MockHttpClient());
        $initiativesService = new \App\Service\TextesInitiativesIndex(sys_get_temp_dir(), $assembleeUrls2, $downloader2);
        $refI = new \ReflectionProperty(\App\Service\TextesInitiativesIndex::class, 'propositionsByAuteur');
        $refI->setAccessible(true);
        $refI->setValue($initiativesService, []);

        $controller = new \App\Controller\DossierController(
            static::getContainer()->get(\App\Repository\TexteRepository::class),
            $dossierIndex,
            $assembleeUrls,
            new \App\Service\SenatUrls('senat.fr'),
        );

        // ensure controller has container for render
        $c = new \ReflectionProperty(\Symfony\Bundle\FrameworkBundle\Controller\AbstractController::class, 'container');
        $c->setAccessible(true);
        $c->setValue($controller, static::getContainer());

        $req2 = new \Symfony\Component\HttpFoundation\Request();
        static::getContainer()->get('request_stack')->push($req2);
        $response = $controller->index();
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\Response::class, $response);
        $content = (string) $response->getContent();
        self::assertStringContainsString('/dossiers/DLR-A', $content);
        self::assertStringContainsString('/dossiers/DLR-B', $content);
    }
}
