<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Command;


use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\Attribute\Required;
use WEBcoast\DceToContentblocks\Repository\DceRepository;
use WEBcoast\DceToContentblocks\Utility\ConfigurationUtility;
use WEBcoast\DceToContentblocks\Utility\MigrationUtility;

#[AsCommand('dce:migrate', 'Migrate DCE to content blocks')]
class MigrateConfigCommand extends Command
{
    protected SymfonyStyle $io;

    protected DceRepository $dceRepository;

    protected ConfigurationUtility $configurationUtility;

    protected MigrationUtility $migrationUtility;

    #[Required]
    public function setDceRepository(DceRepository $dceRepository): void
    {
        $this->dceRepository = $dceRepository;
    }

    #[Required]
    public function setConfigurationUtility(ConfigurationUtility $configurationUtility): void
    {
        $this->configurationUtility = $configurationUtility;
    }

    #[Required]
    public function setMigrationUtility(MigrationUtility $migrationUtility): void
    {
        $this->migrationUtility = $migrationUtility;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        $migrationConfiguration = $this->configurationUtility->buildMigrationConfiguration();

        foreach ($migrationConfiguration as $dceIdentifier => $migrationInstructions) {
            $this->migrationUtility->createContentBlockConfiguration($dceIdentifier, $migrationInstructions);
        }

        return Command::SUCCESS;
    }
}
