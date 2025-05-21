<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Command;


use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\Attribute\Required;
use WEBcoast\DceToContentblocks\Repository\DceRepository;
use WEBcoast\DceToContentblocks\Utility\MigrationUtility;

#[AsCommand('dce:migrate', 'Migrate DCE to content blocks')]
class MigrateConfigCommand extends Command
{
    protected SymfonyStyle $io;

    protected DceRepository $dceRepository;

    protected MigrationUtility $migrationUtility;

    #[Required]
    public function setDceRepository(DceRepository $dceRepository): void
    {
        $this->dceRepository = $dceRepository;
    }

    #[Required]
    public function setMigrationUtility(MigrationUtility $migrationUtility): void
    {
        $this->migrationUtility = $migrationUtility;
    }

    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('dceIdentifier', InputArgument::OPTIONAL, 'The identifier of the DCE to migrate');
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        if (!$input->hasArgument('dceIdentifier') || !$input->getArgument('dceIdentifier')) {
            $dces = $this->dceRepository->fetchAll()->fetchAllAssociative();
            $style = new SymfonyStyle($input, $output);
            $style->section('Available DCEs');
            $style->table(['uid', 'title', 'identifier'], $dces);

            $input->setArgument('dceIdentifier', $style->ask('Which DCE do you want to migrate?', null, function (string $dceIdentifier) use ($dces) {
                if (empty($dceIdentifier) || (!in_array($dceIdentifier, array_column($dces, 'identifier')) && !in_array($dceIdentifier, array_column($dces, 'uid')))) {
                    throw new \RuntimeException('Invalid DCE identifier');
                }

                return $dceIdentifier;
            }));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        $this->migrationUtility->setIo($this->io);
        $this->migrationUtility->migrate($input->getArgument('dceIdentifier'));

        return Command::SUCCESS;
    }
}
