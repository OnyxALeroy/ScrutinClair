<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261022085114 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le titre court (IA) sur les textes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE texte ADD titre_court TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE texte ADD titre_court_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE texte DROP titre_court');
        $this->addSql('ALTER TABLE texte DROP titre_court_at');
    }
}
