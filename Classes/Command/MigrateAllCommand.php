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
    /**
     * Configure the command by defining the name, options and arguments
     */
    protected function configure(): void
    {
        $this->addArgument('grididentifier', InputArgument::REQUIRED, 'Gridelements identifier to migrate all the elements from this type')
            ->addArgument('containeridentifier', InputArgument::REQUIRED, 'The new EXT:container element-identifier e.g. ce_columns2')
            ->addArgument('flexformidentifier', InputArgument::REQUIRED, 'If you want a clean flexform field, write "clean". If you want a flexform value from the TCA than write the identifier or if you want the old flexform value than write "old".')
            ->addArgument('oldcolumids', InputArgument::REQUIRED, 'The old Column-ID/s, separated with a commar without space')
            ->addArgument('columnids', InputArgument::REQUIRED, 'New Column-ID/s, separated with a commar without space. It must be used at the end of the argument list and it must have the same order as the old columids')
            ->setHelp('Migrate gridelements to container.' . LF . 'You must have registered the EXT:container elements before! And please make a backup from your database before start the migration' . LF . 'This function migrates all gridelements and content elements with the selected gridelements-layout keys. Not tested is a migration of nested grid elements');
    }

    /**
     * Executes the command to migrate the elements
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int error code
     * @throws DBALException
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $grididentifier = $input->getArgument('grididentifier');
        $containeridentifier = $input->getArgument('containeridentifier');
        $flexformoption = $input->getArgument('flexformidentifier');
        $oldcolumids = $input->getArgument('oldcolumids');
        $columnids = $input->getArgument('columnids');
        $io->writeln('Entries for migration: ' . $grididentifier . ' | ' . $containeridentifier . ' | ' . $flexformoption . ' | ' . $oldcolumids . ' | ' . $columnids);
        $io->writeln('Migration starts now');

        $elementInfos = [];
        $elementInfos[$grididentifier]['active'] = 1;
        $columIds = array_combine(explode(',', $oldcolumids), explode(',', $columnids));
        foreach ($columIds as $oldColumnId => $newColumnId) {
            $elementInfos[$grididentifier]['columns'][$oldColumnId]['columnid'] = $newColumnId;
            $elementInfos[$grididentifier]['columns'][$oldColumnId]['sameCid'] = null;
        }

        $elementInfos[$grididentifier]['containername'] = $containeridentifier;
        if ($flexformoption === 'clean') {
            $elementInfos[$grididentifier]['flexFormvalue'] = '';
            $elementInfos[$grididentifier]['cleanFlexForm'] = 1;
        } elseif ($flexformoption === 'old') {
            $elementInfos[$grididentifier]['flexFormvalue'] = 1;
            $elementInfos[$grididentifier]['cleanFlexForm'] = '';
        } else {
            $flexFormValue = $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config']['ds']['*,' . $flexformoption];
            $flexFormInfos = '';
            if (substr_compare('FILE:', $flexFormValue, 0, 5) || $flexFormValue == '') {
                $flexFormInfos .= $flexFormValue;
            } else {
                $flexFormInfos .= file_get_contents(\TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName(substr($flexFormValue, 5)));
            }
            $elementInfos[$grididentifier]['flexFormvalue'] = $flexFormInfos;
            $elementInfos[$grididentifier]['cleanFlexForm'] = '';
        }

        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $migrationRepository = $objectManager->get(MigrationRepository::class);

        $migrateAll = $migrationRepository->updateAllElements($elementInfos);

        if ($migrateAll) {
            $io->writeln('The migration is completed');
            return 0;
        }

        $io->writeln('The migration is failed');
        return 1;
    }
}
