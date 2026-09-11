<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903195319 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE exam_subjects ADD question_paper_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE exam_subjects ADD CONSTRAINT FK_E841D624857D386D FOREIGN KEY (question_paper_id) REFERENCES question_papers (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_E841D624857D386D ON exam_subjects (question_paper_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE exam_subjects DROP CONSTRAINT FK_E841D624857D386D');
        $this->addSql('DROP INDEX IDX_E841D624857D386D');
        $this->addSql('ALTER TABLE exam_subjects DROP question_paper_id');
    }
}
