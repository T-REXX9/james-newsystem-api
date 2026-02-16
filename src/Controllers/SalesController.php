<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SalesRepository;
use App\Support\Exceptions\HttpException;

final class SalesController
{
    public function __construct(private readonly SalesRepository $repo)
    {
    }

    public function flowByInquiry(array $params, array $query = [], array $body = []): array
    {
        $inquiryRefno = $params['inquiryRefno'] ?? '';
        if ($inquiryRefno === '') {
            throw new HttpException(422, 'inquiryRefno is required');
        }

        $flow = $this->repo->getFlowByInquiryRef($inquiryRefno);
        if ($flow === null) {
            throw new HttpException(404, 'Inquiry not found');
        }

        return $flow;
    }

    public function flowBySalesOrder(array $params, array $query = [], array $body = []): array
    {
        $soRefno = $params['soRefno'] ?? '';
        if ($soRefno === '') {
            throw new HttpException(422, 'soRefno is required');
        }

        $flow = $this->repo->getFlowBySalesOrderRef($soRefno);
        if ($flow === null) {
            throw new HttpException(404, 'Sales order not found');
        }

        return $flow;
    }
}
