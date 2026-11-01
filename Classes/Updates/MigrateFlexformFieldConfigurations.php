<?php

declare(strict_types = 1);

namespace T3\Dce\Updates;

/*  | This extension is made with love for TYPO3 CMS and is licensed
 *  | under GNU General Public License.
 *  |
 *  | (c) 2012-2026 Armin Vieweg <armin@v.ieweg.de>
 */
use T3\Dce\Components\ContentElementGenerator\Generator;
use T3\Dce\Utility\TcaConfigurationNormalizer;
use TYPO3\CMS\Core\Attribute\UpgradeWizard;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Migrates the stored DCE field configurations to the current TCA syntax.
 *
 * FlexFormTools migrates the old syntax on the fly, which is deprecated.
 * The migration is idempotent and also keeps untouched configurations
 * working, because the generator normalizes its output as well.
 */
#[UpgradeWizard('dceFlexformFieldConfigurations')]
final class MigrateFlexformFieldConfigurations implements UpgradeWizardInterface
{
    private const TABLE = 'tx_dce_domain_model_dcefield';

    public function getTitle(): string
    {
        return 'Migrate DCE field configurations to current TCA syntax';
    }

    public function getDescription(): string
    {
        return 'Rewrites the FlexForm field definitions stored by DCE so FlexFormTools no longer migrates them on the fly.';
    }

    public function getPrerequisites(): array
    {
        return [];
    }

    public function updateNecessary(): bool
    {
        $queryBuilder = $this->getQueryBuilder();
        $count = $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->like('configuration', $queryBuilder->createNamedParameter('%<eval>%required%')),
                    $queryBuilder->expr()->like('configuration', $queryBuilder->createNamedParameter('%inputDateTime%')),
                    $queryBuilder->expr()->like('configuration', $queryBuilder->createNamedParameter('%<items type="array">%')),
                ),
            )
            ->executeQuery()
            ->fetchOne();

        return (int)$count > 0;
    }

    public function executeUpdate(): bool
    {
        $queryBuilder = $this->getQueryBuilder();
        $rows = $queryBuilder
            ->select('uid', 'configuration')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('deleted', 0))
            ->executeQuery()
            ->fetchAllAssociative();

        $connection = $queryBuilder->getConnection();
        foreach ($rows as $row) {
            $configuration = (string)$row['configuration'];
            if ($configuration === '') {
                continue;
            }
            $migrated = TcaConfigurationNormalizer::normalizeXml($configuration);
            if ($migrated === $configuration) {
                continue;
            }
            $connection->update(
                self::TABLE,
                ['configuration' => $migrated, 'tstamp' => time()],
                ['uid' => (int)$row['uid']],
            );
        }

        GeneralUtility::makeInstance(Generator::class)->rebuild();

        return true;
    }

    private function getQueryBuilder(): QueryBuilder
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
    }
}
