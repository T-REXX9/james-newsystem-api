<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CollectionRepository;
use App\Support\Exceptions\HttpException;
use DateTimeImmutable;

final class CollectionController
{
    public function __construct(private readonly CollectionRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return $this->repo->listCollections(
            $mainId,
            (string) ($query['search'] ?? ''),
            (string) ($query['status'] ?? ''),
            (string) ($query['date_from'] ?? ''),
            (string) ($query['date_to'] ?? '')
        );
    }

    public function summary(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $dateType = strtolower(trim((string) ($query['date_type'] ?? 'today')));
        [$dateFrom, $dateTo] = $this->resolveDateRange(
            $dateType,
            (string) ($query['date_from'] ?? ''),
            (string) ($query['date_to'] ?? '')
        );
        $limit = (int) ($query['limit'] ?? 200);
        if ($limit <= 0) {
            $limit = 200;
        }
        $limit = min($limit, 1000);

        return $this->repo->collectionSummary(
            $mainId,
            $dateFrom,
            $dateTo,
            (string) ($query['bank'] ?? ''),
            (string) ($query['check_status'] ?? ''),
            (string) ($query['customer_id'] ?? ''),
            (string) ($query['collection_type'] ?? ''),
            $limit
        );
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        return $this->repo->createCollection($mainId, $userId);
    }

    public function show(array $params, array $query = [], array $body = []): array
    {
        $refno = (string) ($params['collectionRefno'] ?? '');
        if ($refno === '') {
            throw new HttpException(422, 'collectionRefno is required');
        }

        $record = $this->repo->getCollection($refno);
        if ($record === null) {
            throw new HttpException(404, 'Collection record not found');
        }

        return $record;
    }

    public function items(array $params, array $query = [], array $body = []): array
    {
        $refno = (string) ($params['collectionRefno'] ?? '');
        if ($refno === '') {
            throw new HttpException(422, 'collectionRefno is required');
        }

        return [
            'collection_refno' => $refno,
            'items' => $this->repo->getCollectionItems($refno),
        ];
    }

    public function unpaid(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        $customerId = (string) ($query['customer_id'] ?? '');
        if ($mainId <= 0 || $customerId === '') {
            throw new HttpException(422, 'main_id and customer_id are required');
        }

        return $this->repo->getUnpaidInvoicesAndOrderSlips($mainId, $customerId);
    }

    public function addPayment(array $params, array $query = [], array $body = []): array
    {
        $refno = (string) ($params['collectionRefno'] ?? '');
        if ($refno === '') {
            throw new HttpException(422, 'collectionRefno is required');
        }

        $required = ['main_id', 'user_id', 'customer_id', 'type', 'amount', 'status', 'collect_date'];
        foreach ($required as $field) {
            if (!isset($body[$field]) || $body[$field] === '') {
                throw new HttpException(422, $field . ' is required');
            }
        }

        $itemId = $this->repo->addCollectionPayment($refno, [
            'main_id' => (int) $body['main_id'],
            'user_id' => (int) $body['user_id'],
            'customer_id' => (string) $body['customer_id'],
            'type' => (string) $body['type'],
            'bank' => (string) ($body['bank'] ?? ''),
            'check_no' => (string) ($body['check_no'] ?? ''),
            'check_date' => (string) ($body['check_date'] ?? ''),
            'amount' => (float) $body['amount'],
            'status' => (string) $body['status'],
            'remarks' => (string) ($body['remarks'] ?? ''),
            'collect_date' => (string) $body['collect_date'],
            'transactions' => is_array($body['transactions'] ?? null) ? $body['transactions'] : [],
        ]);

        return [
            'collection_refno' => $refno,
            'collection_item_id' => $itemId,
        ];
    }

    public function action(array $params, array $query = [], array $body = []): array
    {
        $refno = (string) ($params['collectionRefno'] ?? '');
        $action = strtolower((string) ($params['action'] ?? ''));
        if ($refno === '' || $action === '') {
            throw new HttpException(422, 'collectionRefno and action are required');
        }

        return match ($action) {
            'submitrecord' => $this->submitRecord($refno, $body),
            'approverecord' => $this->approveRecord($refno, $body),
            'disapproverecord' => $this->disapproveRecord($refno, $body),
            'cancelrecord' => $this->repo->setCollectionStatus($refno, 'Cancelled'),
            'postrecord' => $this->repo->postCollection($refno),
            'posttoledger' => $this->repo->rebuildCollectionLedger($refno),
            default => throw new HttpException(422, 'Unsupported action'),
        };
    }

    public function deleteItem(array $params, array $query = [], array $body = []): array
    {
        $itemId = (int) ($params['itemId'] ?? 0);
        if ($itemId <= 0) {
            throw new HttpException(422, 'itemId is required');
        }

        $this->repo->deleteCollectionItem($itemId);
        return ['deleted' => true, 'collection_item_id' => $itemId];
    }

    public function updateItem(array $params, array $query = [], array $body = []): array
    {
        $itemId = (int) ($params['itemId'] ?? 0);
        if ($itemId <= 0) {
            throw new HttpException(422, 'itemId is required');
        }

        return $this->repo->updateCollectionPaymentLine($itemId, $body);
    }

    public function postItems(array $params, array $query = [], array $body = []): array
    {
        $refno = (string) ($params['collectionRefno'] ?? '');
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        $itemIds = is_array($body['item_ids'] ?? null) ? $body['item_ids'] : [];

        if ($refno === '' || $mainId <= 0 || $userId <= 0 || count($itemIds) === 0) {
            throw new HttpException(422, 'collectionRefno, main_id, user_id and item_ids are required');
        }

        return $this->repo->postCollectionItems($refno, $itemIds, $mainId, $userId);
    }

    public function approverLogs(array $params, array $query = [], array $body = []): array
    {
        $refno = (string) ($params['collectionRefno'] ?? '');
        if ($refno === '') {
            throw new HttpException(422, 'collectionRefno is required');
        }

        return [
            'collection_refno' => $refno,
            'logs' => $this->repo->getApproverLogs($refno),
        ];
    }

    private function submitRecord(string $refno, array $body): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required for submitrecord');
        }
        return $this->repo->submitCollection($refno, $mainId);
    }

    private function approveRecord(string $refno, array $body): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $staffId = (string) ($body['staff_id'] ?? '');
        if ($mainId <= 0 || $staffId === '') {
            throw new HttpException(422, 'main_id and staff_id are required for approverecord');
        }

        return $this->repo->approveOrDisapproveCollection(
            $refno,
            $mainId,
            $staffId,
            'Approve',
            isset($body['remarks']) ? (string) $body['remarks'] : null
        );
    }

    private function disapproveRecord(string $refno, array $body): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $staffId = (string) ($body['staff_id'] ?? '');
        if ($mainId <= 0 || $staffId === '') {
            throw new HttpException(422, 'main_id and staff_id are required for disapproverecord');
        }

        return $this->repo->approveOrDisapproveCollection(
            $refno,
            $mainId,
            $staffId,
            'Disapprove',
            isset($body['remarks']) ? (string) $body['remarks'] : null
        );
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveDateRange(string $dateType, string $dateFrom, string $dateTo): array
    {
        $today = new DateTimeImmutable('today');

        return match ($dateType) {
            'all' => ['1900-01-01', $today->format('Y-m-d')],
            'today' => [$today->format('Y-m-d'), $today->format('Y-m-d')],
            'week' => [$today->modify('-1 week')->format('Y-m-d'), $today->format('Y-m-d')],
            'month' => [$today->modify('-1 month')->format('Y-m-d'), $today->format('Y-m-d')],
            'year' => [$today->modify('-1 year')->format('Y-m-d'), $today->format('Y-m-d')],
            'custom' => [
                $this->normalizeDateOrToday($dateFrom, $today),
                $this->normalizeDateOrToday($dateTo, $today),
            ],
            default => [$today->format('Y-m-d'), $today->format('Y-m-d')],
        };
    }

    private function normalizeDateOrToday(string $value, DateTimeImmutable $fallback): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $fallback->format('Y-m-d');
        }
        $ts = strtotime($trimmed);
        if ($ts === false) {
            return $fallback->format('Y-m-d');
        }
        return date('Y-m-d', $ts);
    }
}
