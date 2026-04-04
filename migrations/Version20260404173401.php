<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260404173401 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE assigned_questionnaire (id INT AUTO_INCREMENT NOT NULL, assigned_at DATETIME NOT NULL, patient_id INT NOT NULL, questionnaire_id INT NOT NULL, assigned_by_id INT DEFAULT NULL, INDEX IDX_BD94E4EA6B899279 (patient_id), INDEX IDX_BD94E4EACE07E8FF (questionnaire_id), INDEX IDX_BD94E4EA6E6F1246 (assigned_by_id), UNIQUE INDEX unique_patient_questionnaire (patient_id, questionnaire_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE assigned_questionnaire ADD CONSTRAINT FK_BD94E4EA6B899279 FOREIGN KEY (patient_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assigned_questionnaire ADD CONSTRAINT FK_BD94E4EACE07E8FF FOREIGN KEY (questionnaire_id) REFERENCES questionnaire (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assigned_questionnaire ADD CONSTRAINT FK_BD94E4EA6E6F1246 FOREIGN KEY (assigned_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE assigned_questionnaire DROP FOREIGN KEY FK_BD94E4EA6B899279');
        $this->addSql('ALTER TABLE assigned_questionnaire DROP FOREIGN KEY FK_BD94E4EACE07E8FF');
        $this->addSql('ALTER TABLE assigned_questionnaire DROP FOREIGN KEY FK_BD94E4EA6E6F1246');
        $this->addSql('DROP TABLE assigned_questionnaire');
    }
}
