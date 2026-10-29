<?php

namespace App\Tests\Service;

use App\Entity\PersonnalitePolitique;
use App\Service\PersonnaliteMatcher;
use PHPUnit\Framework\TestCase;

final class PersonnaliteMatcherTest extends TestCase
{
    public function testSameIdentityWithAccentsAndParticlesProducesSameKey(): void
    {
        $matcher = new PersonnaliteMatcher();
        $date = new \DateTimeImmutable('1960-11-20');

        $senateur = [
            'sennomuse' => 'Létard',
            'senprenomuse' => 'Valérie',
            'sendatnai' => '1960-11-20 00:00:00',
        ];

        $personnalite = new PersonnalitePolitique();
        $personnalite->setNom('LETARD');
        $personnalite->setPrenom('Valérie');
        $personnalite->setDateNaissance($date);

        self::assertSame(
            $matcher->cle('Létard', 'Valérie', $date),
            $matcher->cleSenateur($senateur),
        );
        self::assertSame($matcher->cleSenateur($senateur), $matcher->clePersonnalite($personnalite));
    }

    public function testDifferentBirthDatesDoNotMatch(): void
    {
        $matcher = new PersonnaliteMatcher();

        self::assertNotSame(
            $matcher->cle('Martin', 'Jean', new \DateTimeImmutable('1960-01-01')),
            $matcher->cle('Martin', 'Jean', new \DateTimeImmutable('1961-01-01')),
        );
    }
}
