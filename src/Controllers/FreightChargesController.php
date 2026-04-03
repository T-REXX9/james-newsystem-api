<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\FreightChargesRepository;
use App\Support\Exceptions\HttpException;
use RuntimeException;

final class FreightChargesController
{
    public function __construct(private readonly FreightChargesRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        return $this->repo->list(
            $mainId,
            trim((string) ($query['search'] ?? '')),
            trim((string) ($query['status'] ?? '')),
            trim((string) ($query['customer_id'] ?? '')),
            trim((string) ($query['month'] ?? '')),
            trim((string) ($query['year'] ?? '')),
            max(1, (int) ($query['page'] ?? 1)),
            max(1, (int) ($query['per_page'] ?? 50))
        );
    }

    public function report(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $dateType = trim((string) ($query['date_type'] ?? 'today'));
        $dateFrom = trim((string) ($query['date_from'] ?? ''));
        $dateTo = trim((string) ($query['date_to'] ?? ''));

        if (strcasecmp($dateType, 'Custom') === 0 && ($dateFrom === '' || $dateTo === '')) {
            throw new HttpException(422, 'date_from and date_to are required for custom report');
        }

        return $this->repo->report($mainId, $dateType, $dateFrom, $dateTo);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }

        $item = $this->repo->getByRefno($refno);
        if ($item === null) {
            throw new HttpException(404, 'Freight charge record not found');
        }

        return $item;
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $userId = trim((string) ($body['user_id'] ?? ''));
        if ($userId === '') {
            throw new HttpException(422, 'user_id is required');
        }

        try {
            return $this->repo->create($mainId, $userId, $body);
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

        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }

        try {
            $item = $this->repo->update($mainId, $refno, $body);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if ($item === null) {
            throw new HttpException(404, 'Freight charge record not found');
        }

        return $item;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $refno = trim((string) ($params['refno'] ?? ''));
        if ($refno === '') {
            throw new HttpException(422, 'refno is required');
        }

        try {
            $ok = $this->repo->delete($mainId, $refno);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        if (!$ok) {
            throw new HttpException(404, 'Freight charge record not found');
        }

        return [
            'deleted' => true,
            'refno' => $refno,
        ];
    }

    public function action(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $userId = trim((string) ($body['user_id'] ?? ''));
        if ($userId === '') {
            throw new HttpException(422, 'user_id is required');
        }

        $refno = trim((string) ($params['refno'] ?? ''));
        $action = trim((string) ($params['action'] ?? ''));
        if ($refno === '' || $action === '') {
            throw new HttpException(422, 'refno and action are required');
        }

        try {
            return $this->repo->action($mainId, $userId, $refno, $action);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }
}
