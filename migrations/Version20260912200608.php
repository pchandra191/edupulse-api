<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260912200608 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add subject_id to attendance_records for subject-wise attendance tracking';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE attendance_records ADD subject_id UUID DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              attendance_records
            ADD
              CONSTRAINT FK_9B5AB64423EDC87 FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE
            SET
              NULL NOT DEFERRABLE
        SQL);
        $this->addSql('CREATE INDEX IDX_9B5AB64423EDC87 ON attendance_records (subject_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE attendance_records DROP CONSTRAINT FK_9B5AB64423EDC87');
        $this->addSql('DROP INDEX IDX_9B5AB64423EDC87');
        $this->addSql('ALTER TABLE attendance_records DROP subject_id');
    }
}
