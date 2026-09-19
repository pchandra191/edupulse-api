<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add startDate and endDate columns to classes table for attendance window calculation';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('classes');

        if (!$table->hasColumn('start_date')) {
            $table->addColumn('start_date', 'date', ['notnull' => false]);
        }

        if (!$table->hasColumn('end_date')) {
            $table->addColumn('end_date', 'date', ['notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('classes');

        if ($table->hasColumn('end_date')) {
            $table->removeColumn('end_date');
        }

        if ($table->hasColumn('start_date')) {
            $table->removeColumn('start_date');
        }
    }
}