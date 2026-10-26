<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Controller\Product;

use Idea89\Assistant\Controller\Product\Gallery;
use Idea89\Assistant\Model\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Helper\Product as ProductHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * GET /idea89/product/gallery: the chat's image viewer asks for it only when
 * a shopper presses the enlarge button (owner request 2026-10-26).
 */
class GalleryTest extends TestCase
{
    private Json&MockObject $json;
    private RequestInterface&MockObject $request;
    private ProductRepositoryInterface&MockObject $repo;
    private ProductHelper&MockObject $productHelper;
    private ImageHelper&MockObject $imageHelper;
    private Config&MockObject $config;
    private Product&MockObject $product;
    /** @var array<string, mixed>|null */
    private ?array $sent = null;
    private int $code = 200;

    protected function setUp(): void
    {
        $this->json = $this->createMock(Json::class);
        $this->json->method('setHttpResponseCode')->willReturnCallback(function ($c) { $this->code = $c; return $this->json; });
        $this->json->method('setData')->willReturnCallback(function ($d) { $this->sent = $d; return $this->json; });
        $this->json->method('setHeader')->willReturnSelf();
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($this->json);

        $this->request = $this->createMock(RequestInterface::class);
        $this->repo = $this->createMock(ProductRepositoryInterface::class);
        $this->productHelper = $this->createMock(ProductHelper::class);
        $this->imageHelper = $this->createMock(ImageHelper::class);
        $this->imageHelper->method('init')->willReturnSelf();
        $file = '';
        $this->imageHelper->method('setImageFile')->willReturnCallback(function ($f) use (&$file) { $file = $f; return $this->imageHelper; });
        $this->imageHelper->method('getUrl')->willReturnCallback(function () use (&$file) { return 'https://shop.test/media/cache/x' . $file; });
        $this->config = $this->createMock(Config::class);
        $this->config->method('isEnabled')->willReturn(true);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $store->method('getWebsiteId')->willReturn(1);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        $this->product = $this->createMock(Product::class);
        $this->product->method('getWebsiteIds')->willReturn([1]);
        $this->product->method('getData')->willReturnCallback(fn ($k = '') => $k === 'media_gallery' ? ['images' => [
            ['file' => '/b/back.jpg', 'label' => 'Back', 'position' => 2],
            ['file' => '/f/front.jpg', 'label' => 'Front', 'position' => 1],
            ['file' => '/h/hidden.jpg', 'label' => '', 'position' => 3, 'disabled' => 1],
            ['file' => '/v/video.jpg', 'label' => 'Film', 'position' => 4, 'media_type' => 'external-video'],
        ]] : null);

        $this->controller = new Gallery($factory, $this->request, $this->repo, $this->productHelper, $this->imageHelper, $stores, $this->config);
    }

    private Gallery $controller;

    private function params(array $p): void
    {
        $this->request->method('getParam')->willReturnCallback(fn ($k) => $p[$k] ?? null);
    }

    public function testEnabledImagesInTheMerchantsOrderWithoutVideos(): void
    {
        $this->params(['id' => '7']);
        $this->repo->method('getById')->with(7, false, 1)->willReturn($this->product);
        $this->productHelper->method('canShow')->willReturn(true);
        $this->controller->execute();
        $this->assertSame(200, $this->code);
        $this->assertSame(['images' => [
            ['url' => 'https://shop.test/media/cache/x/f/front.jpg', 'label' => 'Front'],
            ['url' => 'https://shop.test/media/cache/x/b/back.jpg', 'label' => 'Back'],
        ]], $this->sent);
    }

    public function testAProductTheStoreDoesNotShowIsNotFound(): void
    {
        $this->params(['id' => '7']);
        $this->repo->method('getById')->willReturn($this->product);
        $this->productHelper->method('canShow')->willReturn(false);
        $this->controller->execute();
        $this->assertSame(404, $this->code);
    }

    public function testAnUnknownProductOrNoIdIsNotFound(): void
    {
        $this->params(['id' => '99']);
        $this->repo->method('getById')->willThrowException(new \Magento\Framework\Exception\NoSuchEntityException());
        $this->controller->execute();
        $this->assertSame(404, $this->code);
    }
}
