<?php

namespace SBublies\Gridtocontainer\Controller;

use Doctrine\DBAL\DBALException;
use SBublies\Gridtocontainer\Domain\Repository\MigrationRepository;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class MigrationController extends ActionController
{
    /**
     * migrationRepository
     *
     * @var MigrationRepository|null
     *
     */
    protected ?MigrationRepository $migrationRepository = null;

    /**
     * @param MigrationRepository|null $migrationRepository
     */
    public function __construct(?MigrationRepository $migrationRepository)
    {
        $this->migrationRepository = $migrationRepository;
    }
}
