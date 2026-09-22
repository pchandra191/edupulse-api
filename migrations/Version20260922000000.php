<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add independent student academic details (academic_year, class, section) to user_profiles';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('user_profiles');

        if (!$table->hasColumn('academic_year_id')) {
            $table->addColumn('academic_year_id', 'uuid', ['notnull' => false]);
        }

        if (!$table->hasColumn('academic_class_id')) {
            $table->addColumn('academic_class_id', 'uuid', ['notnull' => false]);
        }

        if (!$table->hasColumn('section_id')) {
            $table->addColumn('section_id', 'uuid', ['notnull' => false]);
        }

        $this->addSql('ALTER TABLE user_profiles ADD CONSTRAINT FK_USER_PROFILE_ACADEMIC_YEAR FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_profiles ADD CONSTRAINT FK_USER_PROFILE_ACADEMIC_CLASS FOREIGN KEY (academic_class_id) REFERENCES classes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_profiles ADD CONSTRAINT FK_USER_PROFILE_SECTION FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_profiles DROP CONSTRAINT FK_USER_PROFILE_SECTION');
        $this->addSql('ALTER TABLE user_profiles DROP CONSTRAINT FK_USER_PROFILE_ACADEMIC_CLASS');
        $this->addSql('ALTER TABLE user_profiles DROP CONSTRAINT FK_USER_PROFILE_ACADEMIC_YEAR');

        $table = $schema->getTable('user_profiles');

        if ($table->hasColumn('section_id')) {
            $table->removeColumn('section_id');
        }

        if ($table->hasColumn('academic_class_id')) {
            $table->removeColumn('academic_class_id');
        }

        if ($table->hasColumn('academic_year_id')) {
            $table->removeColumn('academic_year_id');
        }
    }
}