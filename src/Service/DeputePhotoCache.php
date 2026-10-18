<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Télécharge et met en cache localement les photos officielles des députés.
 */
final class DeputePhotoCache
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly AssembleeUrls $urls,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Retourne le chemin local de la photo, en la téléchargeant au besoin.
     */
    public function ensure(string $uid): ?string
    {
        $path = $this->cachePath($uid);
        if (is_file($path)) {
            return $path;
        }

        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $this->urls->photoUrl($uid), [
                'timeout' => 15,
                'max_redirects' => 5,
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
            if (!str_starts_with($contentType, 'image/')) {
                return null;
            }

            $content = $response->getContent();
        } catch (\Throwable) {
            return null;
        }

        if (\strlen($content) < 500) {
            return null;
        }

        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (false === file_put_contents($tmp, $content)) {
            return null;
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);

            return null;
        }

        return $path;
    }

    private function cachePath(string $uid): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9]/', '', $uid);

        return $this->projectDir . '/var/photos_deputes/' . $safe . '.jpg';
    }
}
