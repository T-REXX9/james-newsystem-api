<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SuggestedStockReportRepository;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class SuggestedStockReportController
{
    public function __construct(private readonly SuggestedStockReportRepository $repo)
    {
    }

    public function customers(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $dateFrom = isset($query['date_from']) ? (string) $query['date_from'] : null;
        $dateTo = isset($query['date_to']) ? (string) $query['date_to'] : null;

        return [
            'items' => $this->repo->listCustomers($mainId, $dateFrom, $dateTo),
        ];
    }

    public function summary(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $dateFrom = isset($query['date_from']) ? (string) $query['date_from'] : null;
        $dateTo = isset($query['date_to']) ? (string) $query['date_to'] : null;
        $customerId = isset($query['customer_id']) ? (string) $query['customer_id'] : null;
        $partNo = trim((string) ($query['part_no'] ?? ''));
        $sortBy = trim((string) ($query['sort_by'] ?? SuggestedStockReportRepository::SORT_QTY_DESC));
        $kivFolder = $this->toBool($query['kiv'] ?? false);
        if ($sortBy === 'kiv-folder') {
            $kivFolder = true;
            $sortBy = SuggestedStockReportRepository::SORT_QTY_DESC;
        }
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(200, (int) ($query['per_page'] ?? 100)));

        return $this->repo->summary(
            $mainId,
            $dateFrom,
            $dateTo,
            $customerId,
            $page,
            $perPage,
            $partNo !== '' ? $partNo : null,
            $sortBy !== '' ? $sortBy : SuggestedStockReportRepository::SORT_QTY_DESC,
            $kivFolder
        );
    }

    public function details(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $dateFrom = isset($query['date_from']) ? (string) $query['date_from'] : null;
        $dateTo = isset($query['date_to']) ? (string) $query['date_to'] : null;
        $customerId = isset($query['customer_id']) ? (string) $query['customer_id'] : null;
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(300, (int) ($query['per_page'] ?? 200)));

        return $this->repo->details($mainId, $dateFrom, $dateTo, $customerId, $page, $perPage);
    }

    public function updateRemark(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $itemId = (int) ($body['item_id'] ?? 0);
        if ($itemId <= 0) {
            throw new HttpException(422, 'item_id is required');
        }

        $remark = trim((string) ($body['remark'] ?? ''));

        $updated = $this->repo->updateRemark($mainId, $itemId, $remark);
        if (!$updated) {
            throw new HttpException(404, 'Suggested stock item not found');
        }

        return ['updated' => true, 'item_id' => $itemId, 'remark' => $remark];
    }

    public function clearNotListed(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $inquiryItemId = isset($body['inquiry_item_id']) ? (int) $body['inquiry_item_id'] : null;
        $partNo = trim((string) ($body['part_no'] ?? ''));
        $itemCode = trim((string) ($body['item_code'] ?? ''));

        if (($inquiryItemId === null || $inquiryItemId <= 0) && $partNo === '' && $itemCode === '') {
            throw new HttpException(422, 'inquiry_item_id, part_no, or item_code is required');
        }

        return $this->repo->clearNotListedRemarks(
            $mainId,
            $inquiryItemId !== null && $inquiryItemId > 0 ? $inquiryItemId : null,
            $partNo,
            $itemCode
        );
    }

    public function addToKiv(array $params = [], array $query = [], array $body = []): array
    {
        [$mainId, $items] = $this->requireKivItems($body);
        $createdBy = trim((string) ($body['user_id'] ?? ''));
        return $this->repo->addToKiv($mainId, $items, $createdBy);
    }

    public function removeFromKiv(array $params = [], array $query = [], array $body = []): array
    {
        [$mainId, $items] = $this->requireKivItems($body);
        return $this->repo->removeFromKiv($mainId, $items);
    }

    public function markAddedToPurchaseRequest(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        if ($mainId <= 0 || $items === []) {
            throw new HttpException(422, 'main_id and items are required');
        }
        return $this->repo->markAddedToPurchaseRequest($mainId, $items);
    }

    public function suppliers(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return [
            'items' => $this->repo->listSuppliers($mainId),
        ];
    }

    public function purchaseOrders(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return [
            'items' => $this->repo->listPurchaseOrders($mainId),
        ];
    }

    public function addPurchaseOrderItem(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        $purchaseRefno = trim((string) ($params['purchaseRefno'] ?? ''));
        if ($purchaseRefno === '') {
            throw new HttpException(422, 'purchaseRefno is required');
        }

        try {
            return $this->repo->addPurchaseOrderItem($mainId, $userId, $purchaseRefno, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    public function createPurchaseOrder(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        try {
            return $this->repo->createPurchaseOrderWithItem($mainId, $userId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    /**
     * @return array{0:int,1:array<int, mixed>}
     */
    private function requireKivItems(array $body): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        if ($items === []) {
            throw new HttpException(422, 'items is required');
        }

        $hasIdentity = false;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $partNo = trim((string) ($item['part_no'] ?? $item['partNo'] ?? ''));
            $itemCode = trim((string) ($item['item_code'] ?? $item['itemCode'] ?? ''));
            $description = trim((string) ($item['description'] ?? ''));
            if ($partNo !== '' || $itemCode !== '' || $description !== '') {
                $hasIdentity = true;
                break;
            }
        }
        if (!$hasIdentity) {
            throw new HttpException(422, 'items must include a part number, item code, or description');
        }

        return [$mainId, $items];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $text = strtolower(trim((string) $value));
        return in_array($text, ['1', 'true', 'yes', 'on'], true);
    }
}
