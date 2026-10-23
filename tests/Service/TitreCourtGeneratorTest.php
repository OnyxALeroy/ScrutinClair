<?php

namespace App\Tests\Service;

use App\Service\OllamaClient;
use App\Service\TitreCourtGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TitreCourtGeneratorTest extends TestCase
{
    private const TITRE = "l'amendement n° 1158 de M. Lenoir à l'article 4 de la proposition de loi relative au droit à l'aide à mourir (nouvelle lecture).";

    public function testAjouteLesReferencesExtraitesDuTitreOfficiel(): void
    {
        $generateur = $this->generateur("Droit à l'aide à mourir");

        self::assertSame(
            'Droit à l\'aide à mourir (article 4, amendement 1158)',
            $generateur->generer(self::TITRE),
        );
    }

    public function testGardeLeSujetSeulSansReference(): void
    {
        $generateur = $this->generateur('Simplification de la vie économique');

        self::assertSame(
            'Simplification de la vie économique',
            $generateur->generer('le projet de loi de simplification de la vie économique (première lecture).'),
        );
    }

    public function testLeSujetTientDansLaLongueurMaximale(): void
    {
        $generateur = $this->generateur('Assouplir la gestion des compétences de l\'eau et de l\'assainissement en France');

        $titreCourt = $generateur->generer(self::TITRE);

        self::assertNotNull($titreCourt);
        self::assertLessThanOrEqual(TitreCourtGenerator::LONGUEUR_MAX, mb_strlen($titreCourt));
        self::assertStringEndsWith('(article 4, amendement 1158)', $titreCourt);
    }

    public function testRefuseUneReponseQuiContientUneReference(): void
    {
        $appels = 0;
        $generateur = $this->generateurAvecReponses(
            ["L'aide à mourir (amendement 1158)", "Droit à l'aide à mourir"],
            $appels,
        );

        self::assertSame(
            'Droit à l\'aide à mourir (article 4, amendement 1158)',
            $generateur->generer(self::TITRE),
        );
        self::assertSame(2, $appels);
    }

    public function testRejetteDeuxFoispuisUtiliseLeRepliParRegles(): void
    {
        $appels = 0;
        $generateur = $this->generateurAvecReponses(
            [self::TITRE, "L'aide à mourir (amendement 1158)"],
            $appels,
        );

        self::assertSame(
            'Droit à l\'aide à mourir (article 4, amendement 1158)',
            $generateur->generer(self::TITRE),
        );
        self::assertSame(2, $appels);
    }

    public function testUneErreurDuModeleRetombeSurLesRegles(): void
    {
        $client = new MockHttpClient(new MockResponse('erreur', ['http_code' => 500]));
        $generateur = new TitreCourtGenerator(new OllamaClient($client, 'modele', 8192));

        self::assertSame(
            'Droit à l\'aide à mourir (article 4, amendement 1158)',
            $generateur->generer(self::TITRE),
        );
    }

    public function testRetourneNullQuandAucuneMethodeNeMarche(): void
    {
        $generateur = $this->generateur('X');

        self::assertNull($generateur->generer('décision n° 2024-100 du conseil constitutionnel.'));
        self::assertNull($generateur->generer('rapport au premier ministre.'));
    }

    public function testExtraitLesReferencesDeToutesLesFormes(): void
    {
        $generateur = $this->generateur('Sujet');

        self::assertSame('article 4, amendement 1158', $generateur->references("l'amendement n° 1158 à l'article 4."));
        self::assertSame('article 4', $generateur->references("l'article 4 du projet de loi."));
        self::assertSame('', $generateur->references('le projet de loi de simplification de la vie économique.'));
        self::assertSame('article 1, amendement 42', $generateur->references('amendement n° 42 après l\'article premier.'));
        self::assertSame('article 12 octies, amendement 7', $generateur->references('n° 7 à l\'article 12 octies.'));
        self::assertSame('article 3, amendement 7', $generateur->references('l\'amendement n° 7 à l\'article 3.'));
        self::assertSame('article 3', $generateur->references('amendements à la suite des articles 3 et 4.'));
        self::assertSame('', $generateur->references("l'article unique de la proposition de loi."));
        self::assertSame('amendement 99', $generateur->references('le sous-amendement n° 99.'));
    }

    public function testNeFaitAucunAppelQuandLeTitreEstVide(): void
    {
        $appels = 0;
        $client = new MockHttpClient(function () use (&$appels): MockResponse {
            ++$appels;

            return new MockResponse(json_encode(['message' => ['content' => 'X']]), [
                'response_headers' => ['Content-Type' => 'application/json'],
            ]);
        });
        $generateur = new TitreCourtGenerator(new OllamaClient($client, 'modele', 8192));

        self::assertNull($generateur->generer('   '));
        self::assertSame(0, $appels);
    }

    public function testLeModeleNeVoitQueLeTitreOfficiel(): void
    {
        $corps = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$corps): MockResponse {
            $corps = json_decode((string) ($options['body'] ?? ''), true);

            return new MockResponse(json_encode(['message' => ['content' => 'Droit à l\'aide à mourir']]), [
                'response_headers' => ['Content-Type' => 'application/json'],
            ]);
        });
        $generateur = new TitreCourtGenerator(new OllamaClient($client, 'modele', 8192));

        $generateur->generer(self::TITRE, 'Fin de vie');

        self::assertIsArray($corps);
        $prompt = $corps['messages'][1]['content'];
        self::assertStringContainsString("l'amendement n° 1158", $prompt);
        self::assertStringContainsString('Fin de vie', $prompt);
        self::assertStringContainsString('40 caractères', $prompt);
    }

    private function generateur(string $reponse): TitreCourtGenerator
    {
        return new TitreCourtGenerator(new OllamaClient($this->client([$reponse]), 'modele', 8192));
    }

    /**
     * @param list<string> $reponses
     */
    private function generateurAvecReponses(array $reponses, ?int &$appels = null): TitreCourtGenerator
    {
        $appels = 0;
        $client = new MockHttpClient(function () use (&$reponses, &$appels): MockResponse {
            $reponse = array_shift($reponses) ?? '';
            ++$appels;

            return new MockResponse(json_encode(['message' => ['content' => $reponse]]), [
                'response_headers' => ['Content-Type' => 'application/json'],
            ]);
        });

        return new TitreCourtGenerator(new OllamaClient($client, 'modele', 8192));
    }

    /**
     * @param list<string> $reponses
     */
    private function client(array $reponses): MockHttpClient
    {
        return new MockHttpClient(function () use (&$reponses): MockResponse {
            $reponse = array_shift($reponses) ?? '';

            return new MockResponse(json_encode(['message' => ['content' => $reponse]]), [
                'response_headers' => ['Content-Type' => 'application/json'],
            ]);
        });
    }
}
