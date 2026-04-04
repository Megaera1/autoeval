<?php

namespace App\Command;

use App\Entity\Questionnaire;
use App\Repository\QuestionnaireRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:import-questionnaire',
    description: 'Importe un ou tous les questionnaires JSON depuis questionnaires/ en base de données.',
)]
class ImportQuestionnaireCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private QuestionnaireRepository $questionnaireRepository,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'file',
            InputArgument::OPTIONAL,
            'Nom du fichier JSON (ex: HAD.json). Laissez vide pour importer tous les fichiers.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = $this->projectDir . '/questionnaires';

        if (!is_dir($dir)) {
            $io->error("Le dossier $dir n'existe pas.");
            return Command::FAILURE;
        }

        $filename = $input->getArgument('file');
        $files = $filename
            ? [$dir . '/' . $filename]
            : glob($dir . '/*.json');

        if (empty($files)) {
            $io->warning('Aucun fichier JSON trouvé.');
            return Command::SUCCESS;
        }

        foreach ($files as $path) {
            if (!file_exists($path)) {
                $io->error("Fichier introuvable : $path");
                continue;
            }

            $raw = file_get_contents($path);
            $data = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $io->error("JSON invalide dans $path : " . json_last_error_msg());
                continue;
            }

            // Le fichier est un tableau de questionnaires
            if (!isset($data[0])) {
                $data = [$data];
            }

            foreach ($data as $qData) {
                $this->importOne($qData, $io);
            }
        }

        $this->em->flush();
        $io->success('Import terminé.');

        return Command::SUCCESS;
    }

    private function importOne(array $data, SymfonyStyle $io): void
    {
        $slug = strtolower($data['id']);

        $questionnaire = $this->questionnaireRepository->findOneBy(['slug' => $slug])
            ?? new Questionnaire();

        $questionnaire->setTitle($data['nom']);
        $questionnaire->setSlug($slug);
        $questionnaire->setDescription($data['instructions'] ?? null);
        $questionnaire->setIsActive(true);

        // Catégorie : nom des sous-échelles s'il y en a, sinon vide
        if (!empty($data['sous_echelles'])) {
            $labels = array_column($data['sous_echelles'], 'label');
            $questionnaire->setCategory(implode(' / ', $labels));
        }

        // On stocke l'intégralité de la structure JSON dans questions
        $questionnaire->setQuestions($data);

        $this->em->persist($questionnaire);
        $io->text(sprintf('  ✓ "%s" (slug: %s)', $questionnaire->getTitle(), $slug));
    }
}
