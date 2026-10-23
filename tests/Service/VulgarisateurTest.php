<?php

namespace App\Tests\Service;

use App\Service\OllamaClient;
use App\Service\Vulgarisateur;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class VulgarisateurTest extends TestCase
{
    public function testTexteCourtUnSeulAppel(): void
    {
        $appels = 0;
        $client = new MockHttpClient(function () use (&$appels): MockResponse {
            ++$appels;

            return new MockResponse(json_encode(['message' => ['content' => 'Résumé']]), [
                'response_headers' => ['Content-Type' => 'application/json'],
            ]);
        });

        $vulgarisateur = new Vulgarisateur(new OllamaClient($client, 'modele', 8192));
        $resultat = $vulgarisateur->vulgariserTexteLoi('Un texte court.');

        self::assertSame('Résumé', $resultat);
        self::assertSame(1, $appels);
    }

    public function testRetireLePreambuleDeVulgarisation(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([
            'message' => ['content' => "Voici une reformulation du texte de loi en 200 à 350 mots, pour le grand public :\n\nCette loi crée une aide pour les entreprises."],
        ]), ['response_headers' => ['Content-Type' => 'application/json']]));

        $vulgarisateur = new Vulgarisateur(new OllamaClient($client, 'modele', 8192));

        self::assertSame(
            'Cette loi crée une aide pour les entreprises.',
            $vulgarisateur->vulgariserTexteLoi('Un texte court.'),
        );
    }

    public function testRetirePlusieursPreambulesEtLeMarkdown(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([
            'message' => ['content' => "**Voici une reformulation du texte de loi en 200 à 350 mots, pour le grand public :**\nEn 200 à 350 mots :\nCette loi crée une aide pour les entreprises."],
        ]), ['response_headers' => ['Content-Type' => 'application/json']]));

        $vulgarisateur = new Vulgarisateur(new OllamaClient($client, 'modele', 8192));

        self::assertSame(
            'Cette loi crée une aide pour les entreprises.',
            $vulgarisateur->vulgariserTexteLoi('Un texte court.'),
        );
    }

    public function testRetireUnPreambuleDePointsCles(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([
            'message' => ['content' => "Voici les principaux points de la proposition de loi sur le droit à l'aide à mourir :\n\n1. Définition du droit à l'aide à mourir."],
        ]), ['response_headers' => ['Content-Type' => 'application/json']]));

        $vulgarisateur = new Vulgarisateur(new OllamaClient($client, 'modele', 8192));

        self::assertSame(
            "1. Définition du droit à l'aide à mourir.",
            $vulgarisateur->vulgariserTexteLoi('Un texte court.'),
        );
    }

    public function testConserveUnePhraseQuiCommenceParVoiciSansFormule(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([
            'message' => ['content' => 'Voici le texte de loi qui crée une aide pour les entreprises.'],
        ]), ['response_headers' => ['Content-Type' => 'application/json']]));

        $vulgarisateur = new Vulgarisateur(new OllamaClient($client, 'modele', 8192));

        self::assertSame(
            'Voici le texte de loi qui crée une aide pour les entreprises.',
            $vulgarisateur->vulgariserTexteLoi('Un texte court.'),
        );
    }

    public function testTexteLongDeclencheUnMapReduce(): void
    {
        $appels = 0;
        $client = new MockHttpClient(function () use (&$appels): MockResponse {
            ++$appels;

            return new MockResponse(json_encode(['message' => ['content' => 'Bloc']]), [
                'response_headers' => ['Content-Type' => 'application/json'],
            ]);
        });

        $paragraphe = str_repeat('La décision est prise après examen. ', 300); // ~10 500 caractères
        $texte = implode("\n\n", array_fill(0, 5, $paragraphe)); // ~52 500 caractères

        $etapes = [];
        $vulgarisateur = new Vulgarisateur(new OllamaClient($client, 'modele', 8192));
        $vulgarisateur->vulgariserTexteLoi($texte, function (int $courant, int $total) use (&$etapes): void {
            $etapes[] = [$courant, $total];
        });

        // Un appel par morceau, puis un appel de synthèse.
        self::assertGreaterThan(1, $appels);
        self::assertSame($appels, \count($etapes) + 1);
        self::assertNotEmpty($etapes);
    }
}
