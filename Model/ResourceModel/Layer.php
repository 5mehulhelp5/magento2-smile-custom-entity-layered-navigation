<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 *
 * @category  Amadeco
 * @package   Amadeco_SmileCustomEntityLayeredNavigation
 * @copyright Copyright (c) Amadeco (https://www.amadeco.fr)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
declare(strict_types=1);

namespace Amadeco\SmileCustomEntityLayeredNavigation\Model\ResourceModel;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Amadeco\SmileCustomEntityLayeredNavigation\Model\Layer\State;
use Smile\ScopedEav\Api\Data\EntityInterface;

/**
 * Layer Resource Model.
 * Provides methods to interact with the entity index based on layer state.
 */
class Layer
{
    /**
     * Entity type code for custom entities
     */
    private const ENTITY_TYPE_CODE = 'smile_custom_entity';

    /**
     * Column storing option IDs or boolean values in the index/int tables
     */
    private const AGGREGATION_FIELD = 'value';

    /**
     * @param ResourceConnection $resourceConnection
     * @param LoggerInterface $logger
     * @param State $state
     * @param EavConfig $eavConfig
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger,
        private readonly State $state,
        private readonly EavConfig $eavConfig
    ) {}

    /**
     * Get a SELECT object for entity IDs matching the currently applied filters,
     * optionally excluding one specific filter (used for calculating facet counts).
     *
     * @param int $storeId
     * @param int $attributeSetId
     * @param int|null $excludeAttributeId Attribute ID to exclude from filtering (for facet counts).
     * @return Select|null
     * @throws LocalizedException
     */
    public function getBaseSelectForFacets(int $storeId, int $attributeSetId, ?int $excludeAttributeId = null): ?Select
    {
        $connection = $this->resourceConnection->getConnection();
        $indexTable = $this->resourceConnection->getTableName('amadeco_custom_entity_index_eav_idx');
        $entityTable = $this->resourceConnection->getTableName('smile_custom_entity');
        $intTable = $this->resourceConnection->getTableName('smile_custom_entity_int');

        if (!$connection->isTableExists($indexTable)) {
            return null;
        }

        // Retrieve is_active attribute configuration using the interface constant
        $activeAttribute = $this->eavConfig->getAttribute(self::ENTITY_TYPE_CODE, EntityInterface::IS_ACTIVE);
        $activeAttributeId = (int) $activeAttribute->getAttributeId();
        $isGlobal = $activeAttribute->getIsGlobal() == ScopedAttributeInterface::SCOPE_GLOBAL;

        $appliedFilters = $this->state->getFiltersData();

        if ($excludeAttributeId !== null && isset($appliedFilters[$excludeAttributeId])) {
            unset($appliedFilters[$excludeAttributeId]);
        }

        $select = $connection->select();
        $select->from(['e' => $entityTable], ['entity_id'])
            ->where('e.attribute_set_id = ?', $attributeSetId);

        // Filter by is_active = 1
        // We join the integer backend table since is_active is an EAV attribute, not a static column.
        if ($isGlobal) {
            // Global scope: simpler join on store_id = 0
            $select->joinInner(
                ['active_idx' => $intTable],
                $connection->quoteInto(
                    "e.entity_id = active_idx.entity_id AND active_idx.attribute_id = ? AND active_idx.store_id = 0 AND active_idx.value = 1",
                    $activeAttributeId
                ),
                []
            );
        } else {
            // Scoped attribute: Join Default (0) and Current Store to handle fallback
            $select->joinLeft(
                ['active_d' => $intTable],
                $connection->quoteInto(
                    "e.entity_id = active_d.entity_id AND active_d.attribute_id = ? AND active_d.store_id = 0",
                    $activeAttributeId
                ),
                []
            );

            if ($storeId > 0) {
                $select->joinLeft(
                    ['active_s' => $intTable],
                    $connection->quoteInto(
                        "e.entity_id = active_s.entity_id AND active_s.attribute_id = ? AND active_s.store_id = ?",
                        $activeAttributeId,
                        $storeId
                    ),
                    []
                );

                // Check Value: Use Store value if exists, otherwise Default value
                $checkActiveSql = $connection->getCheckSql(
                    'active_s.value_id IS NOT NULL',
                    'active_s.value',
                    'active_d.value'
                );
                $select->where($checkActiveSql . ' = 1');
            } else {
                $select->where('active_d.value = 1');
            }
        }

        if (empty($appliedFilters)) {
            return $select;
        }

        $aliasCounter = 0;
        foreach ($appliedFilters as $attributeId => $value) {
            $alias = 'filter_' . $aliasCounter++;
            
            $conditions = [
                "{$alias}.entity_id = e.entity_id",
                $connection->quoteInto("{$alias}.attribute_id = ?", $attributeId),
                $connection->quoteInto("{$alias}.store_id = ?", $storeId)
            ];

            if (is_array($value)) {
                $conditions[] = $connection->quoteInto(
                    "{$alias}." . self::AGGREGATION_FIELD . ' IN (?)', 
                    $value
                );
            } else {
                $conditions[] = $connection->quoteInto(
                    "{$alias}." . self::AGGREGATION_FIELD . ' = ?', 
                    $value
                );
            }

            $select->joinInner(
                [$alias => $indexTable],
                implode(' AND ', $conditions),
                []
            );
        }

        return $select;
    }
}
