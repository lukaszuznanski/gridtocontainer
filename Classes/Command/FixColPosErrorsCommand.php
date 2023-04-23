<?php

namespace SBublies\Gridtocontainer\Command;

use Doctrine\DBAL\DBALException;
use SBublies\Gridtocontainer\Domain\Repository\MigrationRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\Exception;
use TYPO3\CMS\Extbase\Object\ObjectManager;

class FixColPosErrorsCommand extends Command
{
    protected function configure(): void
    {
        $this->setHelp('Fix colPos in tt_content');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int error code
     * @throws DBALException
     * @throws Exception|\Doctrine\DBAL\Driver\Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->writeln('Fix colPos in tt_content starts now');

        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $migrationRepository = $objectManager->get(MigrationRepository::class);

        $migrateAll = $migrationRepository->fixColPosErrors();

        if ($migrateAll) {
            $io->writeln('Fix colPos in tt_content is completed');
            return 0;
        }

        $io->writeln('Fix colPos in tt_content is failed');
        return 1;
    }
}
