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

namespace Amadeco\SmileCustomEntityLayeredNavigation\Block;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Eav\Api\Data\AttributeSetInterface;
use Smile\CustomEntity\Model\CustomEntity;
use Smile\CustomEntity\Model\ResourceModel\CustomEntity\Collection;
use Amadeco\SmileCustomEntityLayeredNavigation\Block\SetList\Toolbar;
use Amadeco\SmileCustomEntityLayeredNavigation\Model\Layer;
use Amadeco\SmileCustomEntityLayeredNavigation\Model\Layer\Resolver as LayerResolver;
use Amadeco\SmileCustomEntityLayeredNavigation\Model\Set\Attribute\Source\SortBy;

/**
 * Custom Entity Set List Block
 *
 * Block responsible for rendering the list of custom entities for an attribute set
 * with layered navigation applied
 */
class SetList extends Template implements IdentityInterface
{
    /**
     * Default toolbar block name
     */
    private string $defaultToolbarBlock = Toolbar::class;

    /**
     * Collection for the current attribute set
     */
    private ?Collection $entityCollection = null;

    /**
     * Layer model
     */
    private Layer $entityLayer;

    /**
     * @param Context $context
     * @param LayerResolver $layerResolver
     * @param array $data Block data.
     */
    public function __construct(
        protected Context $context,
        private LayerResolver $layerResolver,
        array $data = []
    ) {
        $this->entityLayer = $layerResolver->get();

        parent::__construct(
            $context,
            $data
        );
    }

    /**
     * Retrieve loaded entity collection
     *
     * @return Collection
     */
    protected function _getEntityCollection(): Collection
    {
        if (null === $this->entityCollection) {
            $this->entityCollection = $this->getLayer()->getEntityCollection();
        }

        return $this->entityCollection;
    }

    /**
     * Retrieve entity layer model
     *
     * @return Layer
     */
    public function getLayer(): Layer
    {
        return $this->entityLayer;
    }

    /**
     * Retrieve loaded category collection
     *
     * @return Collection
     */
    public function getLoadedEntityCollection(): Collection
    {
        return $this->_getEntityCollection();
    }

    /**
     * Return current attribute set.
     */
    public function getAttributeSet(): ?AttributeSetInterface
    {
        $layer = $this->getLayer();
        return $layer->getCurrentAttributeSet();
    }

    /**
     * Retrieve current view mode
     *
     * @return string
     */
    public function getMode(): string
    {
        if ($this->getChildBlock('toolbar')) {
            return $this->getChildBlock('toolbar')->getCurrentMode();
        }

        return $this->getDefaultListingMode();
    }

    /**
     * Get listing mode for entities if toolbar is removed from layout.
     * Use the general configuration for entity list mode from config path catalog/custom_entity/list_mode as default value
     * or mode data from block declaration from layout.
     *
     * @return string
     */
    private function getDefaultListingMode(): string
    {
        // default Toolbar when the toolbar layout is not used
        $defaultToolbar = $this->getToolbarBlock();
        $availableModes = $defaultToolbar->getModes();

        // layout config mode
        $mode = $this->getData('mode');

        if (!$mode || !isset($availableModes[$mode])) {
            // default config mode
            $mode = $defaultToolbar->getCurrentMode();
        }

        return (string)$mode;
    }

    /**
     * Prepare layout - configure and set up toolbar, apply sorting and filters
     *
     * @return $this
     */
    protected function _beforeToHtml()
    {
        $collection = $this->_getEntityCollection();

        $this->addToolbarBlock($collection);

        if (!$collection->isLoaded()) {
            $collection->load();
        }

        $attributeSetId = $this->getAttributeSet()->getAttributeSetId();
        if ($attributeSetId) {
            foreach ($collection as $entity) {
                $entity->setData('attribute_set_id', $attributeSetId);
            }
        }

        return parent::_beforeToHtml();
    }

    /**
     * Add toolbar block from entity listing layout
     *
     * @param Collection $collection
     */
    private function addToolbarBlock(Collection $collection): void
    {
        $toolbarLayout = $this->getToolbarFromLayout();

        if ($toolbarLayout) {
            $this->configureToolbar($toolbarLayout, $collection);
        }
    }

    /**
     * Retrieve Toolbar block from layout or a default Toolbar
     *
     * @return Toolbar
     */
    public function getToolbarBlock(): Toolbar
    {
        $block = $this->getToolbarFromLayout();

        if (!$block) {
            $blockName = $this->getNameInLayout() ? $this->getNameInLayout() . '_toolbar' : 'smile_custom_entity_toolbar';
            $block = $this->getLayout()->createBlock(
                $this->defaultToolbarBlock,
                $blockName
            );
        }

        return $block;
    }

    /**
     * Get toolbar block from layout
     *
     * @return Toolbar|false
     */
    private function getToolbarFromLayout()
    {
        $blockName = $this->getToolbarBlockName();

        if ($blockName) {
            $block = $this->getLayout()->getBlock($blockName);
            if ($block instanceof Toolbar) {
                return $block;
            }
        }

        return false;
    }

    /**
     * Retrieve additional blocks html
     *
     * @return string
     */
    public function getAdditionalHtml()
    {
        return $this->getChildHtml('additional');
    }

    /**
     * Retrieve list toolbar HTML
     *
     * @return string
     */
    public function getToolbarHtml()
    {
        return $this->getChildHtml('toolbar');
    }

    /**
     * Set collection.
     *
     * @param Collection $collection
     * @return $this
     */
    public function setCollection($collection)
    {
        $this->entityCollection = $collection;
        return $this;
    }

    /**
     * Add attribute.
     *
     * @param array|string|integer $code
     * @return $this
     */
    public function addAttribute($code)
    {
        $this->_getEntityCollection()->addAttributeToSelect($code);
        return $this;
    }

    /**
     * Prepare Sort By fields from Custom Entity Set Data
     *
     * @param AttributeSetInterface|null $set
     * @return $this
     */
    public function prepareSortableFieldsBySet($set)
    {
        $defaultSortBy = SortBy::toArray();
        if (!$this->getAvailableOrders()) {
            $this->setAvailableOrders($defaultSortBy);
        }
        $availableOrders = $this->getAvailableOrders();
        if (!$this->getSortBy()) {
            $entitySortBy = $this->getDefaultSortBy() ?: key($defaultSortBy);
            if ($entitySortBy) {
                if (isset($availableOrders[$entitySortBy])) {
                    $this->setSortBy($entitySortBy);
                }
            }
        }

        return $this;
    }

    /**
     * Return block identities for cache
     *
     * @return array
     */
    public function getIdentities(): array
    {
        $identities = [];

        $attributeSet = $this->getLayer()->getCurrentAttributeSet();
        if ($attributeSet) {
            $identities[] = CustomEntity::CACHE_CUSTOM_ENTITY_SET_TAG . '_' . $attributeSet->getAttributeSetId();
        }

        foreach ($this->_getEntityCollection() as $entity) {
            $entityIdentities = $entity->getIdentities();
            if ($entityIdentities) {
                foreach ($entityIdentities as $identity) {
                    $identities[] = $identity;
                }
            }
        }

        return array_unique($identities);
    }

    /**
     * Configures the Toolbar block with options from this block and configured entity collection.
     *
     * @param Toolbar $toolbar
     * @param Collection $collection
     * @return void
     */
    private function configureToolbar(Toolbar $toolbar, Collection $collection): void
    {
        // use sortable parameters
        $orders = $this->getAvailableOrders();
        if ($orders) {
            $toolbar->setAvailableOrders($orders);
        }
        $sort = $this->getSortBy();
        if ($sort) {
            $toolbar->setDefaultOrder($sort);
        }
        $dir = $this->getDefaultDirection();
        if ($dir) {
            $toolbar->setDefaultDirection($dir);
        }
        $modes = $this->getModes();
        if ($modes) {
            $toolbar->setModes($modes);
        }
        // set collection to toolbar and apply sort
        $toolbar->setCollection($collection);
        $this->setChild('toolbar', $toolbar);
    }
}
