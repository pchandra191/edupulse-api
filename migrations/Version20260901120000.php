<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add image_file_reference column to questions table to support organizer-uploaded question images';
    }

    public function up(Schema $schema): void
    {
        $questions = $schema->getTable('questions');
        if (!$questions->hasColumn('image_file_reference')) {
            $questions->addColumn('image_file_reference', 'string', [
                'length' => 500,
                'notnull' => false,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $questions = $schema->getTable('questions');
        if ($questions->hasColumn('image_file_reference')) {
            $questions->dropColumn('image_file_reference');
        }
    }
}
