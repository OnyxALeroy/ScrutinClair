<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261026112723 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE groupe_politique ADD chambre VARCHAR(8) DEFAULT \'AN\' NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_groupe_chambre_uid ON groupe_politique (chambre, uid)');
        $this->addSql('ALTER TABLE personnalite_politique ADD uid_senat VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE personnalite_politique ADD circonscription_senat VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE personnalite_politique ADD photo_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE personnalite_politique ADD photo_credit VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE personnalite_politique ADD groupe_politique_senat_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE personnalite_politique ADD CONSTRAINT FK_3926F7C7A44AC57F FOREIGN KEY (groupe_politique_senat_id) REFERENCES groupe_politique (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3926F7C7CAF56FB7 ON personnalite_politique (uid_senat)');
        $this->addSql('CREATE INDEX IDX_3926F7C7A44AC57F ON personnalite_politique (groupe_politique_senat_id)');
        $this->addSql('ALTER TABLE texte ADD chambre VARCHAR(8) DEFAULT \'AN\' NOT NULL');
        $this->addSql('ALTER TABLE texte ADD date_scrutin DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE texte ADD resultat VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE texte ADD non_votants SMALLINT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_texte_chambre_date ON texte (chambre, date_scrutin)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_groupe_chambre_uid');
        $this->addSql('ALTER TABLE groupe_politique DROP chambre');
        $this->addSql('ALTER TABLE personnalite_politique DROP CONSTRAINT FK_3926F7C7A44AC57F');
        $this->addSql('DROP INDEX UNIQ_3926F7C7CAF56FB7');
        $this->addSql('DROP INDEX IDX_3926F7C7A44AC57F');
        $this->addSql('ALTER TABLE personnalite_politique DROP uid_senat');
        $this->addSql('ALTER TABLE personnalite_politique DROP circonscription_senat');
        $this->addSql('ALTER TABLE personnalite_politique DROP photo_url');
        $this->addSql('ALTER TABLE personnalite_politique DROP photo_credit');
        $this->addSql('ALTER TABLE personnalite_politique DROP groupe_politique_senat_id');
        $this->addSql('DROP INDEX idx_texte_chambre_date');
        $this->addSql('ALTER TABLE texte DROP chambre');
        $this->addSql('ALTER TABLE texte DROP date_scrutin');
        $this->addSql('ALTER TABLE texte DROP resultat');
        $this->addSql('ALTER TABLE texte DROP non_votants');
    }
}
