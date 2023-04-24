<?php

namespace SBublies\Gridtocontainer\Command;

/***
 *
 * This file is part of the "Gridtocontainer" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2022 by Stefan Bublies <project@sbublies.de>
 *
 ***/

use Doctrine\DBAL\DBALException;
use SBublies\Gridtocontainer\Domain\Repository\MigrationRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\Exception;
use TYPO3\CMS\Extbase\Object\ObjectManager;

class MigrateAllCommand extends Command
{
    protected function configure(): void
    {
        $this->setHelp('Migrate gridelements to container');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws DBALException
     * @throws Exception
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->writeln('Migration starts now');

        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $migrationRepository = $objectManager->get(MigrationRepository::class);
        $migrateAll = $migrationRepository->updateAllElements();

        if ($migrateAll) {
            $io->writeln('The migration is completed');
            return 0;
        }

        $io->writeln('The migration is failed');
        return 1;
    }
}
