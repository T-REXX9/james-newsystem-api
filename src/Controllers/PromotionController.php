<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PromotionRepository;
use App\Repositories\PromotionProductRepository;
use App\Repositories\PromotionPostingRepository;
use App\Support\Exceptions\HttpException;

final class PromotionController
{
    public function __construct(
        private readonly PromotionRepository $promotionRepo,
        private readonly PromotionProductRepository $productRepo,
        private readonly PromotionPostingRepository $postingRepo
    ) {
    }

    // ========================================================================
    // Promotion Management
    // ========================================================================

    public function listPromotions(array $params = [], array $query = [], array $body = []): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(500, (int) ($query['per_page'] ?? 50)));
        $status = trim((string) ($query['status'] ?? ''));
        $search = trim((string) ($query['search'] ?? ''));
        $includeDeleted = isset($query['include_deleted']) && $query['include_deleted'] !== 'false';

        return $this->promotionRepo->list($page, $perPage, $status, $search, $includeDeleted);
    }

    public function getPromotion(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['promotionId'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'promotionId is required');
        }

        $promotion = $this->promotionRepo->show($id);
        if ($promotion === null) {
            throw new HttpException(404, 'Promotion not found');
        }

        return $promotion;
    }

    public function createPromotion(array $params = [], array $query = [], array $body = []): array
    {
        $title = trim((string) ($body['campaign_title'] ?? ''));
        $endDate = trim((string) ($body['end_date'] ?? ''));

        if ($title === '') {
            throw new HttpException(422, 'campaign_title is required');
        }
        if ($endDate === '') {
            throw new HttpException(422, 'end_date is required');
        }

        $pdo = $this->promotionRepo->pdo();

        try {
            $pdo->beginTransaction();

            $created = $this->promotionRepo->create([
                'campaign_title' => $title,
                'description' => $body['description'] ?? null,
                'start_date' => $body['start_date'] ?? null,
                'end_date' => $endDate,
                'status' => $body['status'] ?? 'Draft',
                'created_by' => $body['created_by'] ?? null,
                'assigned_to' => $body['assigned_to'] ?? [],
                'target_platforms' => $body['target_platforms'] ?? [],
                'target_all_clients' => $body['target_all_clients'] ?? true,
                'target_client_ids' => $body['target_client_ids'] ?? [],
                'target_cities' => $body['target_cities'] ?? [],
            ]);

            if ($created === null) {
                throw new HttpException(500, 'Failed to create promotion');
            }

            $promotionId = trim((string) ($created['id'] ?? ''));
            if ($promotionId === '') {
                throw new HttpException(500, 'Promotion creation returned an invalid id');
            }

            $createdProducts = [];
            foreach (($body['products'] ?? []) as $product) {
                if (!is_array($product)) {
                    throw new HttpException(422, 'products must be an array of product payloads');
                }

                $productId = trim((string) ($product['product_id'] ?? ''));
                if ($productId === '') {
                    throw new HttpException(422, 'Each product requires product_id');
                }

                $createdProduct = $this->productRepo->create([
                    'promotion_id' => $promotionId,
                    'product_id' => $productId,
                    'promo_price_aa' => $product['promo_price_aa'] ?? null,
                    'promo_price_bb' => $product['promo_price_bb'] ?? null,
                    'promo_price_cc' => $product['promo_price_cc'] ?? null,
                    'promo_price_dd' => $product['promo_price_dd'] ?? null,
                    'promo_price_vip1' => $product['promo_price_vip1'] ?? null,
                    'promo_price_vip2' => $product['promo_price_vip2'] ?? null,
                ]);

                if ($createdProduct === null) {
                    throw new HttpException(500, sprintf('Failed to create promotion product for product_id %s', $productId));
                }

                $createdProducts[] = $createdProduct;
            }

            $createdPostings = [];
            foreach (($body['target_platforms'] ?? []) as $platformName) {
                $platform = trim((string) $platformName);
                if ($platform === '') {
                    continue;
                }

                $createdPosting = $this->postingRepo->create([
                    'promotion_id' => $promotionId,
                    'platform_name' => $platform,
                    'status' => 'Not Posted',
                ]);

                if ($createdPosting === null) {
                    throw new HttpException(500, sprintf('Failed to create promotion posting for platform %s', $platform));
                }

                $createdPostings[] = $createdPosting;
            }

            $pdo->commit();

            $created['products'] = $createdProducts;
            $created['postings'] = $createdPostings;

            return $created;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($exception instanceof HttpException) {
                throw $exception;
            }

            throw new HttpException(500, 'Failed to create promotion with related records: ' . $exception->getMessage());
        }
    }

    public function updatePromotion(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['promotionId'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'promotionId is required');
        }

        $updated = $this->promotionRepo->update($id, $body);
        if ($updated === null) {
            throw new HttpException(404, 'Promotion not found');
        }

        return $updated;
    }

    public function deletePromotion(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['promotionId'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'promotionId is required');
        }

        $success = $this->promotionRepo->delete($id);
        if (!$success) {
            throw new HttpException(404, 'Promotion not found');
        }

        return ['ok' => true];
    }

    public function getPromotionsByStatus(array $params = [], array $query = [], array $body = []): array
    {
        $status = trim((string) ($params['status'] ?? ''));
        if ($status === '') {
            throw new HttpException(422, 'status is required');
        }

        $limit = max(1, min(500, (int) ($query['limit'] ?? 100)));

        return [
            'data' => $this->promotionRepo->getByStatus($status, $limit),
        ];
    }

    public function getActivePromotions(array $params = [], array $query = [], array $body = []): array
    {
        $limit = max(1, min(500, (int) ($query['limit'] ?? 50)));

        return [
            'data' => $this->promotionRepo->getActive($limit),
        ];
    }

    // ========================================================================
    // Promotion Products
    // ========================================================================

    public function listProducts(array $params = [], array $query = [], array $body = []): array
    {
        $promotionId = trim((string) ($params['promotionId'] ?? ''));
        if ($promotionId === '') {
            throw new HttpException(422, 'promotionId is required');
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(500, (int) ($query['per_page'] ?? 100)));

        return $this->productRepo->listByPromotion($promotionId, $page, $perPage);
    }

    public function getProduct(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['productId'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'productId is required');
        }

        $product = $this->productRepo->show($id);
        if ($product === null) {
            throw new HttpException(404, 'Product not found');
        }

        return $product;
    }

    public function addProduct(array $params = [], array $query = [], array $body = []): array
    {
        $promotionId = trim((string) ($params['promotionId'] ?? ''));
        $productId = trim((string) ($body['product_id'] ?? ''));

        if ($promotionId === '') {
            throw new HttpException(422, 'promotionId is required');
        }
        if ($productId === '') {
            throw new HttpException(422, 'product_id is required');
        }

        $created = $this->productRepo->create([
            'promotion_id' => $promotionId,
            'product_id' => $productId,
            'promo_price_aa' => $body['promo_price_aa'] ?? null,
            'promo_price_bb' => $body['promo_price_bb'] ?? null,
            'promo_price_cc' => $body['promo_price_cc'] ?? null,
            'promo_price_dd' => $body['promo_price_dd'] ?? null,
            'promo_price_vip1' => $body['promo_price_vip1'] ?? null,
            'promo_price_vip2' => $body['promo_price_vip2'] ?? null,
        ]);

        if ($created === null) {
            throw new HttpException(500, 'Failed to add product');
        }

        return $created;
    }

    public function updateProduct(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['productId'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'productId is required');
        }

        $updated = $this->productRepo->update($id, $body);
        if ($updated === null) {
            throw new HttpException(404, 'Product not found');
        }

        return $updated;
    }

    public function deleteProduct(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['productId'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'productId is required');
        }

        $success = $this->productRepo->delete($id);
        if (!$success) {
            throw new HttpException(404, 'Product not found');
        }

        return ['ok' => true];
    }

    // ========================================================================
    // Promotion Postings
    // ========================================================================

    public function listPostings(array $params = [], array $query = [], array $body = []): array
    {
        $promotionId = trim((string) ($params['promotionId'] ?? ''));
        if ($promotionId === '') {
            throw new HttpException(422, 'promotionId is required');
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(500, (int) ($query['per_page'] ?? 100)));
        $status = trim((string) ($query['status'] ?? ''));

        return $this->postingRepo->listByPromotion($promotionId, $page, $perPage, $status);
    }

    public function getPosting(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['postingId'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'postingId is required');
        }

        $posting = $this->postingRepo->show($id);
        if ($posting === null) {
            throw new HttpException(404, 'Posting not found');
        }

        return $posting;
    }

    public function createPosting(array $params = [], array $query = [], array $body = []): array
    {
        $promotionId = trim((string) ($params['promotionId'] ?? ''));
        $platform = trim((string) ($body['platform_name'] ?? ''));

        if ($promotionId === '') {
            throw new HttpException(422, 'promotionId is required');
        }
        if ($platform === '') {
            throw new HttpException(422, 'platform_name is required');
        }

        $created = $this->postingRepo->create([
            'promotion_id' => $promotionId,
            'platform_name' => $platform,
            'posted_by' => $body['posted_by'] ?? null,
            'post_url' => $body['post_url'] ?? null,
            'screenshot_url' => $body['screenshot_url'] ?? null,
            'status' => $body['status'] ?? 'Not Posted',
        ]);

        if ($created === null) {
            throw new HttpException(500, 'Failed to create posting');
        }

        return $created;
    }

    public function updatePosting(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['postingId'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'postingId is required');
        }

        $updated = $this->postingRepo->updatePosting($id, $body);
        if ($updated === null) {
            throw new HttpException(404, 'Posting not found');
        }

        return $updated;
    }

    public function reviewPosting(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['postingId'] ?? ''));
        $status = trim((string) ($body['status'] ?? ''));
        $reviewedBy = trim((string) ($body['reviewed_by'] ?? ''));

        if ($id === '') {
            throw new HttpException(422, 'postingId is required');
        }
        if ($status === '') {
            throw new HttpException(422, 'status is required');
        }
        if ($reviewedBy === '') {
            throw new HttpException(422, 'reviewed_by is required');
        }

        $updated = $this->postingRepo->reviewPosting($id, $status, $reviewedBy, $body['rejection_reason'] ?? '');
        if ($updated === null) {
            throw new HttpException(404, 'Posting not found');
        }

        return $updated;
    }

    public function getPendingReview(array $params = [], array $query = [], array $body = []): array
    {
        $limit = max(1, min(500, (int) ($query['limit'] ?? 50)));

        return [
            'data' => $this->postingRepo->getPendingReview($limit),
        ];
    }

    public function deletePosting(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['postingId'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'postingId is required');
        }

        $success = $this->postingRepo->delete($id);
        if (!$success) {
            throw new HttpException(404, 'Posting not found');
        }

        return ['ok' => true];
    }

    // ========================================================================
    // Promotion Stats & Extended Operations
    // ========================================================================

    public function getStats(array $params = [], array $query = [], array $body = []): array
    {
        return $this->promotionRepo->getStats();
    }

    public function getAssignedPromotions(array $params = [], array $query = [], array $body = []): array
    {
        $userId = trim((string) ($query['user_id'] ?? ''));
        if ($userId === '') {
            throw new HttpException(422, 'user_id is required');
        }
        $limit = max(1, min(500, (int) ($query['limit'] ?? 100)));
        return [
            'data' => $this->promotionRepo->getAssigned($userId, $limit),
        ];
    }

    public function extendPromotion(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['promotionId'] ?? ''));
        $newEndDate = trim((string) ($body['end_date'] ?? ''));

        if ($id === '') {
            throw new HttpException(422, 'promotionId is required');
        }
        if ($newEndDate === '') {
            throw new HttpException(422, 'end_date is required');
        }

        $result = $this->promotionRepo->extend($id, $newEndDate);
        if ($result === null) {
            throw new HttpException(404, 'Promotion not found');
        }

        // Update product prices if provided
        if (!empty($body['price_updates']) && is_array($body['price_updates'])) {
            foreach ($body['price_updates'] as $update) {
                $productId = $update['product_id'] ?? '';
                if ($productId !== '') {
                    $this->productRepo->update($productId, $update);
                }
            }
        }

        return $result;
    }

    public function batchAddProducts(array $params = [], array $query = [], array $body = []): array
    {
        $promotionId = trim((string) ($params['promotionId'] ?? ''));
        if ($promotionId === '') {
            throw new HttpException(422, 'promotionId is required');
        }

        $products = $body['products'] ?? [];
        if (empty($products)) {
            throw new HttpException(422, 'products array is required');
        }

        $created = $this->productRepo->batchCreate($promotionId, $products);
        return ['data' => $created];
    }

    public function removeProductByProductId(array $params = [], array $query = [], array $body = []): array
    {
        $promotionId = trim((string) ($params['promotionId'] ?? ''));
        $productId = trim((string) ($params['productId'] ?? ''));

        if ($promotionId === '' || $productId === '') {
            throw new HttpException(422, 'promotionId and productId are required');
        }

        $success = $this->productRepo->deleteByPromotionAndProduct($promotionId, $productId);
        if (!$success) {
            throw new HttpException(404, 'Product not found in promotion');
        }

        return ['ok' => true];
    }

    public function uploadScreenshot(array $params = [], array $query = [], array $body = []): array
    {
        // Handle base64 image upload - save to local filesystem
        $imageData = $body['image_data'] ?? '';
        $promotionId = $body['promotion_id'] ?? '';
        $platform = $body['platform'] ?? '';

        if ($imageData === '' || $promotionId === '' || $platform === '') {
            throw new HttpException(422, 'image_data, promotion_id, and platform are required');
        }

        // Decode base64
        $matches = [];
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $imageData, $matches)) {
            $ext = $matches[1];
            $data = base64_decode($matches[2]);
        } else {
            $data = base64_decode($imageData);
            $ext = 'png';
        }

        // Save to uploads directory
        $uploadsDir = dirname(__DIR__, 2) . '/public/uploads/promotion-screenshots';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }

        $filename = $promotionId . '_' . $platform . '_' . time() . '.' . $ext;
        $filepath = $uploadsDir . '/' . $filename;
        file_put_contents($filepath, $data);

        // Return the URL
        $baseUrl = rtrim((string)($_ENV['APP_URL'] ?? 'http://127.0.0.1:8081'), '/');
        $url = $baseUrl . '/uploads/promotion-screenshots/' . $filename;

        return ['url' => $url];
    }
}
