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
 * @copyright Copyright (c) Amadeco (https://www.amadeco.fr) - Ilan Parmentier
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
declare(strict_types=1);

namespace Amadeco\SmileCustomEntityLayeredNavigation\Model\ResourceModel\Layer\Filter;

use Amadeco\SmileCustomEntityLayeredNavigation\Model\Layer\Filter\AbstractFilter;
use Amadeco\SmileCustomEntityLayeredNavigation\Model\ResourceModel\Layer as LayerResource;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Model\ResourceModel\Db\Context; // <--- CORRECTED IMPORT
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for attribute filter operations.
 *
 * This resource model is responsible for applying filters to the custom entity collection
 * and calculating the counts for faceted navigation.
 */
class Attribute extends AbstractDb
{
    /**
     * @var LayerResource Service to retrieve base selects for facet calculation
     */
    private LayerResource $layerResource;

    /**
     * Constructor.
     *
     * @param Context $context
     * @param LayerResource $layerResource
     * @param string|null $connectionName
     */
    public function __construct(
        Context $context,
        LayerResource $layerResource,
        ?string $connectionName = null
    ) {
        $this->layerResource = $layerResource;
        parent::__construct($context, $connectionName);
    }

    /**
     * Initialize connection and define main table.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('amadeco_custom_entity_index_eav_idx', 'entity_id');
    }

    /**
     * Apply attribute filter to entity collection.
     *
     * Filters the collection by joining the index table for the specific attribute value.
     * Handles both single value (string/int) and multiple values (array) for multiselect.
     *
     * @param AbstractFilter $filter
     * @param int|string|array $value
     * @return $this
     */
    public function applyFilterToCollection($filter, $value): static
    {
        $collection = $filter->getLayer()->getEntityCollection();
        $attribute = $filter->getAttributeModel();
        $connection = $this->getConnection();
        $tableAlias = $attribute->getAttributeCode() . '_idx';

        // Support for multiselect: use IN (?) if value is an array, otherwise use = ?
        $valueCondition = is_array($value)
            ? $connection->quoteInto("{$tableAlias}.value IN (?)", $value)
            : $connection->quoteInto("{$tableAlias}.value = ?", $value);

        $conditions = [
            "{$tableAlias}.entity_id = e.entity_id",
            $connection->quoteInto("{$tableAlias}.attribute_id = ?", $attribute->getAttributeId()),
            $connection->quoteInto("{$tableAlias}.store_id = ?", $collection->getStoreId()),
            $valueCondition,
        ];

        $collection->getSelect()->join(
            [$tableAlias => $this->getMainTable()],
            implode(' AND ', $conditions),
            []
        );

        // Ensure distinct results if multiple rows match (common in multiselect)
        $collection->getSelect()->distinct(true);

        return $this;
    }

    /**
     * Retrieve array with entity counts per attribute option.
     *
     * Uses the LayerResource to obtain a select object that includes all other active filters
     * (except the current one) to ensure accurate counts for multi-select or single-select facets.
     *
     * @param AbstractFilter $filter
     * @return array<string, string|int> Array of values and their corresponding counts
     */
    public function getCount($filter): array
    {
        // Retrieve the current attribute set ID from the Layer.
        // This is necessary to scope the base select correctly if the context requires it.
        $attributeSetId = 0;
        $layer = $filter->getLayer();
        
        if (method_exists($layer, 'getCurrentAttributeSet') && $layer->getCurrentAttributeSet()) {
            $attributeSetId = (int)$layer->getCurrentAttributeSet()->getId();
        }

        // Fetch the base select with all filters applied EXCEPT the current attribute.
        $select = $this->layerResource->getBaseSelectForFacets(
            (int)$filter->getStoreId(),
            $attributeSetId,
            (int)$filter->getAttributeModel()->getId()
        );

        if (!$select) {
            return [];
        }

        $connection = $this->getConnection();
        $attribute = $filter->getAttributeModel();
        $tableAlias = 'count_idx';

        $conditions = [
            "{$tableAlias}.entity_id = e.entity_id",
            $connection->quoteInto("{$tableAlias}.attribute_id = ?", $attribute->getAttributeId()),
            $connection->quoteInto("{$tableAlias}.store_id = ?", $filter->getStoreId()),
        ];

        $select->join(
            [$tableAlias => $this->getMainTable()],
            implode(' AND ', $conditions),
            [
                'value',
                'count' => new Expression("COUNT({$tableAlias}.entity_id)")
            ]
        )->group(
            "{$tableAlias}.value"
        );

        return $connection->fetchPairs($select);
    }
}
