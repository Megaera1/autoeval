<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-unverified-users',
    description: 'Supprime les comptes patients dont l\'email n\'a jamais été confirmé (inscriptions abandonnées ou robots).',
)]
class PurgeUnverifiedUsersCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Ancienneté minimale (en jours) des comptes à supprimer.', '7')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les comptes concernés sans les supprimer.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = (int) $input->getOption('days');
        if ($days < 1) {
            $io->error('L\'option --days doit être un entier supérieur ou égal à 1.');

            return Command::INVALID;
        }

        $users = $this->userRepository->findUnverifiedPatientsCreatedBefore(
            new \DateTimeImmutable(sprintf('-%d days', $days)),
        );

        if ($users === []) {
            $io->success('Aucun compte non confirmé à supprimer.');

            return Command::SUCCESS;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        foreach ($users as $user) {
            $io->writeln(sprintf(' - %s (créé le %s)', $user->getEmail(), $user->getCreatedAt()->format('d/m/Y H:i')));
            if (!$dryRun) {
                $this->em->remove($user);
            }
        }

        if ($dryRun) {
            $io->note(sprintf('%d compte(s) seraient supprimés (--dry-run).', count($users)));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d compte(s) non confirmé(s) supprimé(s).', count($users)));

        return Command::SUCCESS;
    }
}
