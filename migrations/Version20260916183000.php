<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add location capture fields (latitude, longitude, accuracy_meters, location_captured_at) to attendance_records for student self check-in';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attendance_records ADD latitude DECIMAL(10,8) DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_records ADD longitude DECIMAL(11,8) DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_records ADD accuracy_meters DECIMAL(8,2) DEFAULT NULL');
        $this->addSql('ALTER TABLE attendance_records ADD location_captured_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attendance_records DROP location_captured_at');
        $this->addSql('ALTER TABLE attendance_records DROP accuracy_meters');
        $this->addSql('ALTER TABLE attendance_records DROP longitude');
        $this->addSql('ALTER TABLE attendance_records DROP latitude');
    }
}
