<?php

namespace App\Tests\Entity;

use App\Entity\GroupePolitique;
use App\Entity\PersonnalitePolitique;
use App\Entity\Texte;
use App\Entity\Vote;
use PHPUnit\Framework\TestCase;

final class TexteTest extends TestCase
{
    private function vote(string $acronyme, string $statut = 'Pour'): Vote
    {
        $groupe = new GroupePolitique();
        $groupe
            ->setUid($acronyme)
            ->setAcronyme($acronyme)
            ->setNom('Groupe '.$acronyme)
            ->setCouleur('#9ca3af')
            ->setOrdre(99);

        $vote = new Vote();
        $vote
            ->setStatut($statut)
            ->setGroupePolitique($groupe)
            ->setPersonalite(new PersonnalitePolitique())
            ->setGroupe($acronyme);

        return $vote;
    }

    public function testGroupesOrdonnesDeLaGaucheVersLaDroite(): void
    {
        $texte = new Texte();
        foreach (['RN', 'LFI-NFP', 'DEM', 'EPR', 'SOC', 'GDR'] as $acronyme) {
            $texte->addVote($this->vote($acronyme));
        }

        $ordre = array_column($texte->getHemicyclePolitique()['groupes'], 'nom');

        self::assertSame(['LFI-NFP', 'GDR', 'SOC', 'DEM', 'EPR', 'RN'], $ordre);
    }

    public function testPositionPolitiqueDesGroupesConnus(): void
    {
        $groupe = new GroupePolitique();
        $groupe->setAcronyme('LFI-NFP')->setOrdre(99);

        self::assertLessThan(
            (new GroupePolitique())->setAcronyme('RN')->setOrdre(99)->getPositionPolitique(),
            $groupe->getPositionPolitique(),
        );
    }
}
