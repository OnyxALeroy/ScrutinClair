<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create import_job table to track admin-imported commands';
    }

    public function up(Schema $schema): void
    {
        // Use Schema API so Doctrine can generate portable SQL for the current platform
        $table = $schema->createTable('import_job');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('command', 'string', ['length' => 191]);
        $table->addColumn('args', 'json');
        $table->addColumn('status', 'string', ['length' => 32]);
        $table->addColumn('started_at', 'datetime', ['notnull' => false]);
        $table->addColumn('finished_at', 'datetime', ['notnull' => false]);
        $table->addColumn('pid', 'string', ['length' => 32, 'notnull' => false]);
        $table->addColumn('log_path', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('created_at', 'datetime');
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('import_job');
    }
}
