<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client minimal pour l'API /api/chat d'Ollama.
 */
final class OllamaClient
{
    public function __construct(
        #[Target('ollama.client')]
        private readonly HttpClientInterface $client,
        #[Autowire('%env(OLLAMA_MODEL)%')]
        private readonly string $model,
        #[Autowire('%env(int:OLLAMA_NUM_CTX)%')]
        private readonly int $numCtx = 8192,
    ) {
    }

    public function modele(): string
    {
        return $this->model;
    }

    /**
     * @param array{num_ctx?: int, temperature?: float, num_predict?: int} $options
     */
    public function chat(string $system, string $prompt, array $options = []): string
    {
        return $this->messages([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $prompt],
        ], $options);
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array{num_ctx?: int, temperature?: float, num_predict?: int} $options
     */
    public function messages(array $messages, array $options = []): string
    {
        try {
            $response = $this->client->request('POST', '/api/chat', [
                'json' => [
                    'model' => $this->model,
                    'messages' => $messages,
                    'stream' => false,
                    'keep_alive' => '30m',
                    'options' => array_merge([
                        'num_ctx' => $this->numCtx,
                        'temperature' => 0.3,
                    ], $options),
                ],
            ]);

            $status = $response->getStatusCode();
            if ($status !== 200) {
                throw new \RuntimeException(sprintf('Ollama a répondu avec le code HTTP %d.', $status));
            }

            $data = $response->toArray(false);
            $contenu = $data['message']['content'] ?? null;
            if (!\is_string($contenu) || trim($contenu) === '') {
                throw new \RuntimeException('Réponse Ollama vide ou inattendue.');
            }

            return trim($contenu);
        } catch (HttpExceptionInterface $e) {
            throw new \RuntimeException('Échec de l\'appel à Ollama : ' . $e->getMessage(), 0, $e);
        }
    }
}
