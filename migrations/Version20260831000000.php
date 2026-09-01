<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260831000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add id and timestamp columns to exam_answer_options table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exam_answer_options ADD COLUMN id UUID');
        $this->addSql('UPDATE exam_answer_options SET id = (md5(random()::text || clock_timestamp()::text))::uuid WHERE id IS NULL');
        $this->addSql('ALTER TABLE exam_answer_options ALTER COLUMN id SET NOT NULL');
        $this->addSql('ALTER TABLE exam_answer_options ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('ALTER TABLE exam_answer_options ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('ALTER TABLE exam_answer_options ADD COLUMN deleted_at TIMESTAMP DEFAULT NULL');
        $this->addSql('ALTER TABLE exam_answer_options DROP CONSTRAINT exam_answer_options_pkey');
        $this->addSql('ALTER TABLE exam_answer_options ADD PRIMARY KEY (id)');
        $this->addSql('ALTER TABLE exam_answer_options ADD CONSTRAINT uniq_exam_answer_option_answer_option UNIQUE (exam_answer_id, question_option_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exam_answer_options DROP CONSTRAINT uniq_exam_answer_option_answer_option');
        $this->addSql('ALTER TABLE exam_answer_options DROP CONSTRAINT exam_answer_options_pkey');
        $this->addSql('ALTER TABLE exam_answer_options DROP COLUMN deleted_at');
        $this->addSql('ALTER TABLE exam_answer_options DROP COLUMN updated_at');
        $this->addSql('ALTER TABLE exam_answer_options DROP COLUMN created_at');
        $this->addSql('ALTER TABLE exam_answer_options DROP COLUMN id');
        $this->addSql('ALTER TABLE exam_answer_options ADD PRIMARY KEY (exam_answer_id, question_option_id)');
    }
}
