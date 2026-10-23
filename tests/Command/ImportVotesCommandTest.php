<?php

namespace App\Tests\Command;

use App\Entity\GroupePolitique;
use App\Entity\PersonnalitePolitique;
use App\Entity\Texte;
use App\Entity\Vote;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class ImportVotesCommandTest extends KernelTestCase
{
    /** @var list<object> */
    private array $crees = [];

    private ?string $dossier = null;

    protected function tearDown(): void
    {
        if ($this->crees !== []) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            foreach (array_reverse($this->crees) as $entite) {
                $frais = $em->find($entite::class, $entite->getId());
                if ($frais !== null) {
                    $em->remove($frais);
                }
            }
            $em->flush();
            $this->crees = [];
        }

        if ($this->dossier !== null && is_dir($this->dossier)) {
            foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
                unlink($fichier);
            }
            rmdir($this->dossier);
            $this->dossier = null;
        }

        parent::tearDown();
    }

    public function testCreeLesVotesDesTroisStatutsPourLeTexteExistant(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $token = bin2hex(random_bytes(5));
        $uidScrutin = 'VTEST' . $token;

        $groupe = (new GroupePolitique())
            ->setUid('PO' . $token)
            ->setNom('Groupe de test ' . $token)
            ->setAcronyme('GT' . substr($token, 0, 3))
            ->setCouleur('#123456')
            ->setOrdre(1);
        $em->persist($groupe);
        $this->crees[] = $groupe;

        $pour = $this->creerPersonnalite($em, $groupe, 'PA' . $token . '1');
        $contre = $this->creerPersonnalite($em, $groupe, 'PA' . $token . '2');
        $abstention = $this->creerPersonnalite($em, $groupe, 'PA' . $token . '3');

        $texte = (new Texte())
            ->setTitre('Scrutin de test ' . $token)
            ->setUidScrutin($uidScrutin);
        $em->persist($texte);
        $this->crees[] = $texte;

        $em->flush();
        $texteId = $texte->getId();

        $this->dossier = sys_get_temp_dir() . '/scrutinclair-votes-' . $token;
        mkdir($this->dossier);
        file_put_contents(
            $this->dossier . '/' . $uidScrutin . '.json',
            json_encode([
                'scrutin' => [
                    'uid' => $uidScrutin,
                    'ventilationVotes' => [
                        'organe' => [
                            'groupes' => [
                                'groupe' => [
                                    'organeRef' => $groupe->getUid(),
                                    'vote' => [
                                        'decompteNominatif' => [
                                            'pours' => ['votant' => ['acteurRef' => $pour->getUid()]],
                                            'contres' => ['votant' => ['acteurRef' => $contre->getUid()]],
                                            'abstentions' => ['votant' => ['acteurRef' => $abstention->getUid()]],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], \JSON_THROW_ON_ERROR),
        );

        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        $sortie = new BufferedOutput();
        $code = $application->run(new ArrayInput([
            'command' => 'app:import-votes',
            '--uid' => $uidScrutin,
            '--dir' => $this->dossier,
        ]), $sortie);

        self::assertSame(0, $code, $sortie->fetch());

        $texte = $em->find(Texte::class, $texteId);
        self::assertInstanceOf(Texte::class, $texte);

        $votes = $em->getRepository(Vote::class)->findBy(['texte' => $texte]);
        self::assertCount(3, $votes);
        array_push($this->crees, ...$votes);

        $statuts = array_map(static fn (Vote $vote): ?string => $vote->getStatut(), $votes);
        sort($statuts);
        self::assertSame(['Abstention', 'Contre', 'Pour'], $statuts);

        foreach ($votes as $vote) {
            self::assertSame($groupe->getId(), $vote->getGroupePolitique()?->getId());
            self::assertSame($groupe->getUid(), $vote->getGroupe());
        }
    }

    private function creerPersonnalite(EntityManagerInterface $em, GroupePolitique $groupe, string $uid): PersonnalitePolitique
    {
        $personnalite = (new PersonnalitePolitique())
            ->setPrenom('Prénom ' . $uid)
            ->setNom('Nom ' . $uid)
            ->setUid($uid)
            ->setGroupePolitique($groupe);
        $em->persist($personnalite);
        $this->crees[] = $personnalite;

        return $personnalite;
    }
}
