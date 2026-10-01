<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AccountsReceivableRepository;
use App\Support\CustomerMergeRedirectResolver;
use App\Support\Exceptions\HttpException;

final class AccountsReceivableController
{
    public function __construct(
        private readonly AccountsReceivableRepository $repo,
        private readonly ?CustomerMergeRedirectResolver $redirects = null
    )
    {
    }

    public function report(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $debtType = (string) ($query['debt_type'] ?? 'All');
        if (!in_array($debtType, ['All', 'Good', 'Bad'], true)) {
            $debtType = 'All';
        }

        return $this->repo->getReport(
            $mainId,
            $this->redirects?->resolve($mainId, trim((string) ($query['customer_id'] ?? '')))['session_id'] ?? trim((string) ($query['customer_id'] ?? '')),
            $debtType,
            (string) ($query['date_type'] ?? 'all'),
            isset($query['date_from']) ? (string) $query['date_from'] : null,
            isset($query['date_to']) ? (string) $query['date_to'] : null
        );
    }
}
