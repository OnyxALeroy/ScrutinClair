<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018152845 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enrichit les personnalités politiques (groupe, circonscription, naissance, profession, fonction).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE personnalite_politique ADD circonscription VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE personnalite_politique ADD date_naissance DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE personnalite_politique ADD profession VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE personnalite_politique ADD fonction VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE personnalite_politique ADD groupe_politique_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE personnalite_politique ADD CONSTRAINT FK_3926F7C7897BF834 FOREIGN KEY (groupe_politique_id) REFERENCES groupe_politique (id)');
        $this->addSql('CREATE INDEX IDX_3926F7C7897BF834 ON personnalite_politique (groupe_politique_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE personnalite_politique DROP CONSTRAINT FK_3926F7C7897BF834');
        $this->addSql('DROP INDEX IDX_3926F7C7897BF834');
        $this->addSql('ALTER TABLE personnalite_politique DROP circonscription');
        $this->addSql('ALTER TABLE personnalite_politique DROP date_naissance');
        $this->addSql('ALTER TABLE personnalite_politique DROP profession');
        $this->addSql('ALTER TABLE personnalite_politique DROP fonction');
        $this->addSql('ALTER TABLE personnalite_politique DROP groupe_politique_id');
    }
}
