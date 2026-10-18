<?php

namespace App\Tests\Service;

use App\Service\OllamaClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OllamaClientTest extends TestCase
{
    public function testRetourneLeContenuDuMessage(): void
    {
        $body = json_encode(['message' => ['role' => 'assistant', 'content' => "Bonjour\n"]]);
        $client = new MockHttpClient(new MockResponse($body, [
            'response_headers' => ['Content-Type' => 'application/json'],
        ]));

        $ollama = new OllamaClient($client, 'qwen2.5:7b-instruct', 8192);

        self::assertSame('Bonjour', $ollama->chat('Système', 'Question'));
        self::assertSame('qwen2.5:7b-instruct', $ollama->modele());
    }

    public function testEchoueSurErreurHttp(): void
    {
        $client = new MockHttpClient(new MockResponse('erreur', ['http_code' => 500]));
        $ollama = new OllamaClient($client, 'modele', 8192);

        $this->expectException(\RuntimeException::class);

        $ollama->chat('Système', 'Question');
    }
}
