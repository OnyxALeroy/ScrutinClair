<?php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Exception;
use Throwable;

class ResilientDownloader
{
    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    public function download(string $url, string $destinationPath): void
    {
        $maxRetries = 15; // On s'autorise 15 reprises en cas de coupure
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $attempt++;

            // 1. On regarde si on a déjà téléchargé un bout du fichier
            $currentSize = file_exists($destinationPath) ? filesize($destinationPath) : 0;
            $headers = [];

            if ($currentSize > 0) {
                // 2. On demande au serveur de reprendre exactement à cet octet
                $headers['Range'] = 'bytes=' . $currentSize . '-';
            }

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => $headers,
                    'timeout' => 30, // Si le serveur fige 30s, on coupe et on relance
                    'http_version' => '1.1' // Évite les bugs HTTP/2 de l'Assemblée
                ]);

                $statusCode = $response->getStatusCode();

                // 416 "Range Not Satisfiable" = le fichier est déjà à 100% sur notre disque
                if ($statusCode === 416) {
                    return;
                }

                // 206 "Partial Content" (reprise réussie) ou 200 "OK" (nouveau fichier)
                if ($statusCode !== 200 && $statusCode !== 206) {
                    throw new Exception("Erreur HTTP inattendue : " . $statusCode);
                }

                // 3. On ouvre le fichier en mode "a" (append/ajout) si on reprend, sinon "w" (write/écrasement)
                $mode = ($currentSize > 0 && $statusCode === 206) ? 'a' : 'w';
                $fileHandler = fopen($destinationPath, $mode);

                // 4. On écrit le flux au fur et à mesure pour ne pas saturer la RAM
                foreach ($this->httpClient->stream($response) as $chunk) {
                    fwrite($fileHandler, $chunk->getContent());
                }

                fclose($fileHandler);

                // Si on arrive ici sans plantage, le fichier est terminé !
                return;

            } catch (Throwable $e) {
                // La connexion a sauté. On ferme proprement le fichier s'il était ouvert.
                if (isset($fileHandler) && is_resource($fileHandler)) {
                    fclose($fileHandler);
                }

                echo "[Tentative $attempt/$maxRetries] Erreur : " . $e->getMessage() . "\n";

                // Si c'est une erreur 404, ça ne sert à rien de réessayer
                if (str_contains($e->getMessage(), '404')) {
                    throw new Exception("Arrêt immédiat : Le fichier n'existe pas (Erreur 404). Vérifiez l'URL : '" . $url . "'");
                }

                // On fait une pause de 2 secondes avant la prochaine tentative
                sleep(2);
            }
        }

        throw new Exception("Échec du téléchargement de $url après $maxRetries tentatives.");
    }
}
