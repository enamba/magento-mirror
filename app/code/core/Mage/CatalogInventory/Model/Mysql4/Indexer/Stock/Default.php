<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category    Mage
 * @package     Mage_CatalogInventory
 * @copyright   Copyright (c) 2009 Irubin Consulting Inc. DBA Varien (http://www.varien.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */


/**
 * CatalogInventory Default Stock Status Indexer Resource Model
 *
 * @category    Mage
 * @package     Mage_CatalogInventor
 * @author      Magento Core Team <core@magentocommerce.com>
 */
class Mage_CatalogInventory_Model_Mysql4_Indexer_Stock_Default
    extends Mage_Catalog_Model_Resource_Eav_Mysql4_Product_Indexer_Abstract
    implements Mage_CatalogInventory_Model_Mysql4_Indexer_Stock_Interface
{
    /**
     * Current Product Type Id
     *
     * @var string
     */
    protected $_typeId;

    /**
     * Product Type is composite flag
     *
     * @var bool
     */
    protected $_isComposite = false;

    /**
     * Initialize connection and define main table name
     *
     */
    protected function _construct()
    {
        $this->_init('cataloginventory/stock_status', 'product_id');
    }

    /**
     * Reindex all stock status data for default logic product type
     *
     * @return Mage_CatalogInventory_Model_Mysql4_Indexer_Stock_Default
     */
    public function reindexAll()
    {
        $this->_prepareIndexTable();
        return $this;
    }

    /**
     * Reindex stock data for defined product ids
     *
     * @param int|array $entityIds
     * @return Mage_CatalogInventory_Model_Mysql4_Indexer_Stock_Default
     */
    public function reindexEntity($entityIds)
    {
        $this->_prepareIndexTable($entityIds);
        return $this;
    }

    /**
     * Set active Product Type Id
     *
     * @param string $typeId
     * @return Mage_CatalogInventory_Model_Mysql4_Indexer_Stock_Default
     */
    public function setTypeId($typeId)
    {
        $this->_typeId = $typeId;
        return $this;
    }

    /**
     * Retrieve active Product Type Id
     *
     * @throws Mage_Core_Exception
     * @return string
     */
    public function getTypeId()
    {
        if (is_null($this->_typeId)) {
            Mage::throwException(Mage::helper('cataloginventory')->__('Undefined product type'));
        }
        return $this->_typeId;
    }

    /**
     * Set Product Type Composite flag
     *
     * @param bool $flag
     * @return Mage_Catalog_Model_Resource_Eav_Mysql4_Product_Indexer_Price_Default
     */
    public function setIsComposite($flag)
    {
        $this->_isComposite = (bool)$flag;
        return $this;
    }

    /**
     * Check product type is composite
     *
     * @return bool
     */
    public function getIsComposite()
    {
        return $this->_isComposite;
    }

    /**
     * Retrieve is Global Manage Stock enabled
     *
     * @return bool
     */
    protected function _isManageStock()
    {
        return Mage::getStoreConfigFlag(Mage_CatalogInventory_Model_Stock_Item::XML_PATH_MANAGE_STOCK);
    }

    /**
     * Prepare stock status data in temporary index table
     *
     * @param int|array $entityIds  the product limitation
     * @return Mage_CatalogInventory_Model_Mysql4_Indexer_Stock_Default
     */
    protected function _prepareIndexTable($entityIds = null)
    {
        $write  = $this->_getWriteAdapter();
        $select = $write->select()
            ->from(array('e' => $this->getTable('catalog/product')), array('entity_id'));
        $this->_addWebsiteJoinToSelect($select, true);
        $this->_addProductWebsiteJoinToSelect($select, 'cw.website_id', 'e.entity_id');
        $select->columns('cw.website_id')
            ->join(
                array('cis' => $this->getTable('cataloginventory/stock')),
                '',
                array('stock_id'))
            ->joinLeft(
                array('cisi' => $this->getTable('cataloginventory/stock_item')),
                'cisi.stock_id = cis.stock_id AND cisi.product_id = e.entity_id',
                array())
            ->columns(new Zend_Db_Expr('IF(cisi.qty > 0, cisi.qty, 0)'))
            ->where('cw.website_id != 0')
            ->where('e.type_id = ?', $this->getTypeId());

        // add limitation of status
        $condition = $write->quoteInto('=?', Mage_Catalog_Model_Product_Status::STATUS_ENABLED);
        $this->_addAttributeToSelect($select, 'status', 'e.entity_id', 'cs.store_id', $condition);

        if ($this->_isManageStock()) {
            $statusExpr = new Zend_Db_Expr('IF(cisi.use_config_manage_stock = 0 AND cisi.manage_stock = 0,'
                . ' 1, cisi.is_in_stock)');
        } else {
            $statusExpr = new Zend_Db_Expr('IF(cisi.use_config_manage_stock = 0 AND cisi.manage_stock = 1,'
                . 'cisi.is_in_stock, 1)');
        }

        $select->columns(array('status' => $statusExpr));

        if (!is_null($entityIds)) {
            $select->where('e.entity_id IN(?)', $entityIds);
        }

        $query = $select->insertFromSelect($this->getIdxTable());
        $write->query($query);

        return $this;
    }
}