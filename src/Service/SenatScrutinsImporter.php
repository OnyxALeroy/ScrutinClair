<?php

namespace App\Service;

use App\Dto\SenatScrutin;
use App\Entity\Texte;
use App\Enum\Chambre;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SenatScrutinsImporter
{
    private const USER_AGENT = 'ScrutinClair/1.0 (+https://scrutinclair.fr)';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly HttpClientInterface $httpClient,
        private readonly SenatUrls $urls,
        private readonly SenatScrutinParser $parser,
        private readonly SenatDataProvider $donnees,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{scrutins: int, nouveaux: int, votes: int, ignores: int, anomalies: int}
     */
    public function importer(int $debut = 2006, ?int $fin = null, ?int $limite = null, bool $force = false, ?callable $onProgress = null): array
    {
        $fin ??= (int) date('Y');
        $this->preparerRepertoires();

        $textes = $this->entityManager->getRepository(Texte::class);
        $personnes = $this->chargerPersonnes();
        $groupes = $this->chargerGroupes();

        $compteurs = ['scrutins' => 0, 'nouveaux' => 0, 'votes' => 0, 'ignores' => 0, 'anomalies' => 0];

        // First pass: estimate total number of scrutins to process for progress reporting
        $totalScrutins = 0;
        for ($annee = $debut; $annee <= $fin; ++$annee) {
            $session = $this->telecharger($this->urls->sessionUrl($annee), $this->cheminSession($annee), 86400);
            if (null === $session) {
                continue;
            }
            $numeros = $this->numerosScrutins($session, $annee);
            $totalScrutins += count($numeros);
        }

        $processed = 0;

        for ($annee = $debut; $annee <= $fin; ++$annee) {
            $session = $this->telecharger($this->urls->sessionUrl($annee), $this->cheminSession($annee), 86400);
            if (null === $session) {
                continue;
            }

            foreach ($this->numerosScrutins($session, $annee) as $numero) {
                if (null !== $limite && $compteurs['scrutins'] >= $limite) {
                    break 2;
                }

                $html = $this->telecharger(
                    $this->urls->scrutinHtmlUrl($annee, $numero),
                    $this->cheminHtml($annee, $numero),
                );
                $json = $this->telecharger(
                    $this->urls->scrutinJsonUrl($annee, $numero),
                    $this->cheminJson($annee, $numero),
                );
                if (null === $html || null === $json) {
                    continue;
                }

                try {
                    $scrutin = $this->parser->parseHtml($html, $this->urls->scrutinHtmlUrl($annee, $numero));
                    $votes = $this->parser->parseVotesJson($json);
                } catch (\Throwable) {
                    ++$compteurs['anomalies'];

                    continue;
                }

                ++$compteurs['scrutins'];
                $uid = 'SEN:'.$scrutin->uid;
                $texte = $textes->findOneBy(['uidScrutin' => $uid]);
                if (null === $texte) {
                    $texte = new Texte();
                    $texte->setUidScrutin($uid);
                    $texte->setChambre(Chambre::Senat);
                    $this->entityManager->persist($texte);
                    ++$compteurs['nouveaux'];
                }

                $this->mettreAJourTexte($texte, $scrutin);

                $existeDeja = !$force && $this->connexionVotesExiste($texte);
                if (!$existeDeja) {
                    $this->entityManager->flush();
                    if ($force) {
                        $this->connection->executeStatement('DELETE FROM vote WHERE texte_id = ?', [$texte->getId()]);
                    }
                    $this->insererVotes($texte, $scrutin, $votes, $personnes, $groupes, $compteurs);
                }

                if (($compteurs['scrutins'] % 100) === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                    gc_collect_cycles();
                }

                usleep(100000);
                // progress update
                if (null !== $onProgress && $totalScrutins > 0) {
                    ++$processed;
                    $pct = (int) floor(($processed / $totalScrutins) * 100);
                    $onProgress(min(100, $pct));
                }
            }
        }

        $this->entityManager->flush();

        return $compteurs;
    }

    private function mettreAJourTexte(Texte $texte, SenatScrutin $scrutin): void
    {
        $resultat = null !== $scrutin->resultat ? ucfirst(mb_strtolower($scrutin->resultat, 'UTF-8')) : null;

        $texte->setTitre($scrutin->titre);
        $texte->setObjet($scrutin->objet);
        $texte->setDossierRef($scrutin->dossierRef);
        $texte->setLien($scrutin->lien);
        $texte->setDescription('Résultat du vote : '.($resultat ?? 'Résultat inconnu'));
        $texte->setDateScrutin($scrutin->dateScrutin);
        $texte->setResultat($resultat);
        $texte->setNonVotants($scrutin->nonVotants);
    }

    private function connexionVotesExiste(Texte $texte): bool
    {
        if (null === $texte->getId()) {
            return false;
        }

        return (bool) $this->connection->fetchOne('SELECT 1 FROM vote WHERE texte_id = ? LIMIT 1', [$texte->getId()]);
    }

    /**
     * @param list<array{matricule: string, vote: string, siege: ?int}> $votes
     * @param array<string, int>                                        $personnes
     * @param array<string, array{id: int, uid: string}>                $groupes
     * @param array<string, int>                                        $compteurs
     */
    private function insererVotes(
        Texte $texte,
        SenatScrutin $scrutin,
        array $votes,
        array $personnes,
        array $groupes,
        array &$compteurs,
    ): void {
        $lignes = [];
        $decompte = ['p' => 0, 'c' => 0, 'a' => 0, 'n' => 0];

        foreach ($votes as $vote) {
            $code = $vote['vote'];
            if (isset($decompte[$code])) {
                ++$decompte[$code];
            }

            $statut = $this->parser->statut($code);
            if (null === $statut) {
                continue;
            }

            $personneId = $personnes[$vote['matricule']] ?? null;
            if (null === $personneId) {
                ++$compteurs['ignores'];

                continue;
            }

            $codeGroupe = null !== $scrutin->dateScrutin
                ? $this->donnees->groupePour($vote['matricule'], $scrutin->dateScrutin)
                : null;
            $groupe = null !== $codeGroupe ? ($groupes[$codeGroupe] ?? null) : null;
            $lignes[] = [
                $statut,
                $texte->getId(),
                $personneId,
                $groupe['uid'] ?? null,
                $groupe['id'] ?? null,
            ];
        }

        if ([] === $lignes) {
            return;
        }

        foreach (array_chunk($lignes, 500) as $lot) {
            $placeholders = implode(', ', array_fill(0, \count($lot), '(?, ?, ?, ?, ?)'));
            $parametres = array_merge(...$lot);
            $this->connection->executeStatement(
                'INSERT INTO vote (statut, texte_id, personalite_id, groupe, groupe_politique_id) VALUES '.$placeholders,
                $parametres,
            );
            $compteurs['votes'] += \count($lot);
        }

        foreach (['pour' => 'p', 'contre' => 'c', 'abstentions' => 'a', 'nonVotants' => 'n'] as $propriete => $code) {
            if (null !== $scrutin->{$propriete} && $scrutin->{$propriete} !== $decompte[$code]) {
                ++$compteurs['anomalies'];
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function chargerPersonnes(): array
    {
        $resultat = [];
        $lignes = $this->connection->fetchAllAssociative(
            'SELECT id, uid_senat FROM personnalite_politique WHERE uid_senat IS NOT NULL',
        );
        foreach ($lignes as $ligne) {
            $resultat[(string) $ligne['uid_senat']] = (int) $ligne['id'];
        }

        return $resultat;
    }

    /**
     * @return array<string, array{id: int, uid: string}>
     */
    private function chargerGroupes(): array
    {
        $resultat = [];
        $lignes = $this->connection->fetchAllAssociative(
            "SELECT id, uid FROM groupe_politique WHERE chambre = 'SENAT'",
        );
        foreach ($lignes as $ligne) {
            $code = str_starts_with((string) $ligne['uid'], 'SEN') ? substr((string) $ligne['uid'], 3) : (string) $ligne['uid'];
            $resultat[$code] = ['id' => (int) $ligne['id'], 'uid' => (string) $ligne['uid']];
        }

        return $resultat;
    }

    /**
     * @return list<int>
     */
    private function numerosScrutins(string $html, int $annee): array
    {
        if (false !== preg_match_all(
            '~href="(?:\.\./)?'.$annee.'/(scr'.$annee.'-(\d+))\.html"~',
            $html,
            $correspondances,
        )) {
            $numeros = array_map('intval', $correspondances[2]);

            return array_values(array_unique($numeros));
        }

        return [];
    }

    private function preparerRepertoires(): void
    {
        foreach (['sessions', 'html', 'json'] as $sousRepertoire) {
            $chemin = $this->projectDir.'/data/senat/'.$sousRepertoire;
            if (!is_dir($chemin) && !mkdir($chemin, 0775, true) && !is_dir($chemin)) {
                throw new \RuntimeException('Impossible de créer '.$chemin);
            }
        }
    }

    private function cheminSession(int $annee): string
    {
        return sprintf('%s/data/senat/sessions/scr%d.html', $this->projectDir, $annee);
    }

    private function cheminHtml(int $annee, int $numero): string
    {
        $repertoire = sprintf('%s/data/senat/html/%d', $this->projectDir, $annee);
        if (!is_dir($repertoire)) {
            mkdir($repertoire, 0775, true);
        }

        return sprintf('%s/scr%d-%d.html', $repertoire, $annee, $numero);
    }

    private function cheminJson(int $annee, int $numero): string
    {
        $repertoire = sprintf('%s/data/senat/json/%d', $this->projectDir, $annee);
        if (!is_dir($repertoire)) {
            mkdir($repertoire, 0775, true);
        }

        return sprintf('%s/scr%d-%d.json', $repertoire, $annee, $numero);
    }

    private function telecharger(string $url, string $chemin, ?int $ttl = null): ?string
    {
        if (is_file($chemin) && filesize($chemin) > 0) {
            if (null === $ttl || filemtime($chemin) >= time() - $ttl) {
                $contenu = file_get_contents($chemin);
                if (false !== $contenu) {
                    return $contenu;
                }
            }
        }

        for ($tentative = 1; $tentative <= 3; ++$tentative) {
            try {
                $reponse = $this->httpClient->request('GET', $url, [
                    'headers' => ['User-Agent' => self::USER_AGENT],
                    'timeout' => 30,
                ]);
                $statut = $reponse->getStatusCode();
                if (404 === $statut || 403 === $statut) {
                    return null;
                }
                if (429 === $statut || $statut >= 500) {
                    sleep($tentative * 2);

                    continue;
                }
                if (200 !== $statut) {
                    return null;
                }

                $contenu = $reponse->getContent(false);
                file_put_contents($chemin, $contenu);

                return $contenu;
            } catch (TransportExceptionInterface) {
                sleep($tentative * 2);
            }
        }

        return null;
    }
}
