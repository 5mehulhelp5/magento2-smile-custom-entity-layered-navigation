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

        // Optimization: Get ID via PHP instead of SQL Subselect
        $activeAttribute = $this->eavConfig->getAttribute(self::ENTITY_TYPE_CODE, EntityInterface::IS_ACTIVE);
        $activeAttributeId = (int) $activeAttribute->getAttributeId();

        $appliedFilters = $this->state->getFiltersData();

        if ($excludeAttributeId !== null && isset($appliedFilters[$excludeAttributeId])) {
            unset($appliedFilters[$excludeAttributeId]);
        }

        $select = $connection->select();
        $select->from(['e' => $entityTable], ['entity_id'])
            ->where('e.attribute_set_id = ?', $attributeSetId);

        // Restored Logic: Allow is_active=1 in either Default (0) or Current Store.
        // This effectively matches the stable branch logic which was robust against data inconsistency.
        $select->joinLeft(
            ['active_idx' => $intTable],
            $connection->quoteInto(
                "e.entity_id = active_idx.entity_id AND active_idx.attribute_id = ? AND active_idx.store_id IN (0, ?)",
                $activeAttributeId,
                $storeId
            ),
            []
        )
        ->where('active_idx.value = 1');

        if (empty($appliedFilters)) {
            return $select;
        }

        $aliasCounter = 0;
        foreach ($appliedFilters as $attributeId => $value) {
            $alias = 'filter_' . $aliasCounter++;
            
            // Standardize Join Conditions for filters
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
