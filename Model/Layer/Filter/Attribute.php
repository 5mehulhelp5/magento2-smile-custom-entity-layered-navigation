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

namespace Amadeco\SmileCustomEntityLayeredNavigation\Model\Layer\Filter;

use Amadeco\SmileCustomEntityLayeredNavigation\Model\Layer;
use Amadeco\SmileCustomEntityLayeredNavigation\Model\Layer\Filter\ItemFactory;
use Amadeco\SmileCustomEntityLayeredNavigation\Model\ResourceModel\Layer\Filter\Attribute as AttributeResource;
use Amadeco\SmileCustomEntityLayeredNavigation\Model\ResourceModel\Layer\Filter\AttributeFactory as AttributeResourceFactory;
use Magento\Catalog\Model\Layer\Filter\Item\DataBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filter\StripTags;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Store\Model\StoreManagerInterface;
use Smile\CustomEntity\Api\Data\CustomEntityAttributeInterface;

/**
 * Layer Attribute Filter.
 *
 * Handles filtering logic for custom entity attributes (select, multiselect).
 * It acts as the bridge between the Layer (Controller/State) and the ResourceModel (Database).
 */
class Attribute extends AbstractFilter
{
    /**
     * @var AttributeResource
     */
    private AttributeResource $resource;

    /**
     * @param ItemFactory $filterItemFactory
     * @param StoreManagerInterface $storeManager
     * @param Layer $layer
     * @param DataBuilder $itemDataBuilder
     * @param AttributeFactory $filterAttributeFactory
     * @param StringUtils $stringUtil
     * @param StripTags $tagFilter
     * @param array $data
     */
    public function __construct(
        ItemFactory $filterItemFactory,
        StoreManagerInterface $storeManager,
        Layer $layer,
        DataBuilder $itemDataBuilder,
        AttributeResourceFactory $resourceFactory,
        protected readonly StringUtils $stringUtil,
        protected readonly StripTags $tagFilter,
        array $data = []
    ) {
        parent::__construct(
            $filterItemFactory,
            $storeManager,
            $layer,
            $itemDataBuilder,
            $data
        );
        $this->resource = $resourceFactory->create();
    }

    /**
     * Apply the filter to the collection.
     *
     * Retrieves the filter value from the request, validates it, applied it to the resource model,
     * and updates the Layer State.
     *
     * @param RequestInterface $request
     * @return $this
     * @throws LocalizedException
     */
    public function apply(RequestInterface $request): static
    {
        // 1. Get the filter value from the request using the attribute code
        $attribute = $this->getAttributeModel();
        $filter = $request->getParam($attribute->getAttributeCode());

        if (empty($filter)) {
            return $this;
        }

        // 2. Normalize input: Support both comma-separated strings (GET standard) and arrays
        $filterValues = is_array($filter) ? $filter : explode(',', (string)$filter);

        // 3. Validate and Clean values
        $filterValues = array_filter(
            $filterValues,
            fn($v) => $this->stringUtil->strlen((string)$v) > 0
        );

        if (empty($filterValues)) {
            return $this;
        }

        // 4. Apply to Resource Model
        // Pass the array directly to support multiselect "IN (?)" queries in the resource
        $this->resource->applyFilterToCollection($this, $filterValues);

        // 5. Update Layer State (User Interface)
        // We create a state tag for the filter so the user sees it's active
        $state = $this->getLayer()->getState();
        foreach ($filterValues as $val) {
            $label = $this->getOptionText($val);
            if ($label) {
                $state->addFilter($this->_createItem($label, $val));
            }
        }

        // Clear items to force regeneration if needed
        $this->_items = [];

        return $this;
    }

    /**
     * Get data array for building attribute filter items.
     *
     * Retrieves options from the attribute source, gets counts from the resource model,
     * and builds the data array for the frontend.
     *
     * @return array<int, array<string, mixed>>
     * @throws LocalizedException
     */
    protected function _getItemsData(): array
    {
        $attribute = $this->getAttributeModel();

        // Ensure the request variable matches the attribute code
        $this->_requestVar = $attribute->getAttributeCode();

        // 1. Get all possible options for this attribute
        $options = $attribute->getFrontend()->getSelectOptions();

        // 2. Get counts for these options based on current filters
        $optionsCount = $this->resource->getCount($this);

        foreach ($options as $option) {
            // Skip invalid options (e.g., placeholder labels with array values)
            if (is_array($option['value']) || !$this->stringUtil->strlen((string)$option['value'])) {
                continue;
            }

            // Check filter type
            if ($this->getAttributeIsFilterable($attribute) === self::ATTRIBUTE_OPTIONS_ONLY_WITH_RESULTS) {
                if (empty($optionsCount[$option['value']])) {
                    continue;
                }

                $this->itemDataBuilder->addItemData(
                    $this->tagFilter->filter($option['label']),
                    $option['value'],
                    $optionsCount[$option['value']]
                );
            } else {
                $this->itemDataBuilder->addItemData(
                    $this->tagFilter->filter($option['label']),
                    $option['value'],
                    isset($optionsCount[$option['value']]) ? $optionsCount[$option['value']] : 0
                );
            }
        }

        return $this->itemDataBuilder->build();
    }

    /**
     * Get Option Text label for a given value ID.
     *
     * @param int|string $value
     * @return string|bool
     * @throws LocalizedException
     */
    protected function getOptionText($value): string|bool
    {
        return $this->getAttributeModel()->getFrontend()->getOption($value);
    }
}