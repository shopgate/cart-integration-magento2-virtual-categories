<?php

declare(strict_types=1);
/**
 * Copyright Shopgate GmbH.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @copyright 2024 Shopgate GmbH
 * @license   http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

namespace Shopgate\VirtualCategory\Observer;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Shopgate\Export\Helper\Product\Utility as HelperProduct;
use Smile\ElasticsuiteCatalog\Model\ResourceModel\Product\Fulltext\CollectionFactory as ProductCollectionFactory;
use Smile\ElasticsuiteVirtualCategory\Model\Category\Filter\Provider as CategoryFilterProvider;
use Magento\Framework\Event\ObserverInterface;

class AddCategoriesToProductExport implements ObserverInterface
{
    private CategoryFilterProvider $categoryFilterProvider;
    private ProductCollectionFactory $productCollectionFactory;
    private CategoryCollectionFactory $categoryCollectionFactory;
    private StoreManagerInterface $storeManager;
    private HelperProduct $helperProduct;
    private CacheInterface $cache;
    private SerializerInterface $serializer;

    /**
     * @param CategoryFilterProvider      $categoryFilterProvider
     * @param ProductCollectionFactory    $productCollectionFactory
     * @param CategoryCollectionFactory   $categoryCollectionFactory
     * @param StoreManagerInterface       $storeManager
     * @param HelperProduct               $helperProduct
     * @param CacheInterface              $cache
     * @param SerializerInterface         $serializer
     */
    public function __construct(
        CategoryFilterProvider $categoryFilterProvider,
        ProductCollectionFactory $productCollectionFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        StoreManagerInterface $storeManager,
        HelperProduct $helperProduct,
        CacheInterface $cache,
        SerializerInterface $serializer
    ) {
        $this->categoryFilterProvider = $categoryFilterProvider;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->storeManager = $storeManager;
        $this->helperProduct = $helperProduct;
        $this->cache = $cache;
        $this->serializer = $serializer;
    }

    public function execute(Observer $observer): void
    {
        $productId               = $observer->getData('product_id');
        $categoryPathsObject     = $observer->getData('category_path');
        $mappedVirtualCategories = $this->getCategoryMapCache();

        if (!isset($mappedVirtualCategories[$productId])) {
            return;
        }

        $sortInflate = 1000000;
        $categoryPaths = $categoryPathsObject->getData('category_path');
        foreach ($mappedVirtualCategories[$productId] as $categoryId => $position) {
            $categoryPaths[$categoryId] = $this->helperProduct->getExportCategory($categoryId, $sortInflate - $position);
        }

        $categoryPathsObject->setData('category_path', $categoryPaths);
    }

    /**
     * @return array
     */
    private function getCategoryMapCache(): array
    {
        $virtualCategories = $this->getVirtualCategoryCollection();
        $productCategoryIds = [];
        foreach ($virtualCategories as $virtualCategory) {
            $cacheKey = implode('|', [
                    'sg-export',
                    $virtualCategory->getStoreId(),
                    $virtualCategory->getId()
                ]
            );

            $data = $this->cache->load($cacheKey);

            if ($data !== false) {
                $productCategoryIds[$virtualCategory->getId()] = $this->serializer->unserialize($data);
                continue;
            }

            $sortFilter = ['category.category_id' => $virtualCategory->getId()];
            $productCollection = $this->productCollectionFactory->create()
                ->setVisibility([Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH])
                ->addQueryFilter($this->categoryFilterProvider->getQueryFilter($virtualCategory))
                ->addSortFilterParameters('position', 'category.position', 'category', $sortFilter)
                ->setOrder('position','asc')
                ->load();

            $indexPosition = 0;
            foreach ($productCollection as $product) {
                $productCategoryIds[$virtualCategory->getId()][$product->getId()] = $product->getPosition() ?? $indexPosition++;
            }

            if (isset($productCategoryIds[$virtualCategory->getId()])) {
                $cacheData = $this->serializer->serialize($productCategoryIds[$virtualCategory->getId()]);
                $this->cache->save($cacheData, $cacheKey, $virtualCategory->getCacheTags());
            }
        }

        $result = [];
        foreach ($productCategoryIds as $categoryId => $productIds) {
            foreach ($productIds as $productId => $position) {
                $result[$productId][$categoryId] = $position;
            }
        }

        return $result;
    }

    /**
     * @return array
     */
    private function getVirtualCategoryCollection(): array
    {
        try {
            return $this->categoryCollectionFactory->create()
                ->addAttributeToSelect('*')
                ->addAttributeToFilter('is_virtual_category', 1)
                ->setStore($this->storeManager->getStore()->getId())
                ->getItems();
        } catch (\Exception $exception) {
            return [];
        }
    }
}
