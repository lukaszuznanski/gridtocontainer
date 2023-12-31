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

class RemoveUnusedElementsCommand extends Command
{
    protected function configure(): void
    {
        $this->setHelp('Remove unused contend and broken elements');
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

        $io->writeln('Remove unused contend and broken elements starts now');

        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $migrationRepository = $objectManager->get(MigrationRepository::class);

        $migrateAll = $migrationRepository->removeUnusedElements();

        if ($migrateAll) {
            $io->writeln('Remove unused contend and broken elements is completed');
            return 0;
        }

        $io->writeln('Remove unused contend and broken elements is failed');
        return 1;
    }
}
