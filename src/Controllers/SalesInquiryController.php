<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SalesInquiryRepository;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class SalesInquiryController
{
    public function __construct(private readonly SalesInquiryRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $search = trim((string) ($query['search'] ?? ''));
        $status = trim((string) ($query['status'] ?? 'active'));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 50));

        return $this->repo->listInquiries($mainId, $search, $status, $page, $perPage);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $inquiryRefno = trim((string) ($params['inquiryRefno'] ?? ''));
        if ($inquiryRefno === '') {
            throw new HttpException(422, 'inquiryRefno is required');
        }

        $record = $this->repo->getInquiry($mainId, $inquiryRefno);
        if ($record === null) {
            throw new HttpException(404, 'Sales inquiry not found');
        }

        return $record;
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        try {
            return $this->repo->createInquiry($mainId, $userId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $inquiryRefno = trim((string) ($params['inquiryRefno'] ?? ''));
        if ($inquiryRefno === '') {
            throw new HttpException(422, 'inquiryRefno is required');
        }

        try {
            $record = $this->repo->updateInquiry($mainId, $inquiryRefno, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        if ($record === null) {
            throw new HttpException(404, 'Sales inquiry not found');
        }

        return $record;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $inquiryRefno = trim((string) ($params['inquiryRefno'] ?? ''));
        if ($inquiryRefno === '') {
            throw new HttpException(422, 'inquiryRefno is required');
        }

        $deleted = $this->repo->cancelInquiry($mainId, $inquiryRefno);
        if (!$deleted) {
            throw new HttpException(404, 'Sales inquiry not found');
        }

        return [
            'deleted' => true,
            'mode' => 'soft_cancel',
            'inquiry_refno' => $inquiryRefno,
        ];
    }

    public function addItem(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $inquiryRefno = trim((string) ($params['inquiryRefno'] ?? ''));
        if ($inquiryRefno === '') {
            throw new HttpException(422, 'inquiryRefno is required');
        }

        try {
            return $this->repo->addItem($mainId, $inquiryRefno, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    public function updateItem(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $itemId = (int) ($params['itemId'] ?? 0);
        if ($itemId <= 0) {
            throw new HttpException(422, 'itemId is required');
        }

        try {
            $item = $this->repo->updateItem($mainId, $itemId, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        if ($item === null) {
            throw new HttpException(404, 'Sales inquiry item not found');
        }

        return $item;
    }

    public function deleteItem(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $itemId = (int) ($params['itemId'] ?? 0);
        if ($itemId <= 0) {
            throw new HttpException(422, 'itemId is required');
        }

        $deleted = $this->repo->deleteItem($mainId, $itemId);
        if (!$deleted) {
            throw new HttpException(404, 'Sales inquiry item not found');
        }

        return [
            'deleted' => true,
            'item_id' => $itemId,
        ];
    }

    public function action(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        if ($mainId <= 0 || $userId <= 0) {
            throw new HttpException(422, 'main_id and user_id are required');
        }

        $inquiryRefno = trim((string) ($params['inquiryRefno'] ?? ''));
        if ($inquiryRefno === '') {
            throw new HttpException(422, 'inquiryRefno is required');
        }

        $action = trim((string) ($params['action'] ?? ''));
        if ($action === '') {
            throw new HttpException(422, 'action is required');
        }

        try {
            if (in_array(strtolower($action), ['convert', 'convert-to-order', 'convertsales'], true)) {
                return $this->repo->convertToSalesOrder($mainId, $userId, $inquiryRefno);
            }
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        throw new HttpException(422, 'Unsupported action: ' . $action);
    }
}
