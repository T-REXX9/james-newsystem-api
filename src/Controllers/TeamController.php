<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\TeamRepository;
use App\Support\Exceptions\HttpException;

final class TeamController
{
    public function __construct(private readonly TeamRepository $repo)
    {
    }

    public function list(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $search = trim((string) ($query['search'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));

        return $this->repo->listTeams($mainId, $search, $page, $perPage);
    }

    public function show(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $teamId = (int) ($params['teamId'] ?? 0);
        if ($teamId <= 0) {
            throw new HttpException(422, 'teamId is required');
        }

        $team = $this->repo->getTeamById($mainId, $teamId);
        if ($team === null) {
            throw new HttpException(404, 'Team not found');
        }

        return $team;
    }

    public function create(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new HttpException(422, 'name is required');
        }

        return $this->repo->createTeam($mainId, $name);
    }

    public function update(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($body['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $teamId = (int) ($params['teamId'] ?? 0);
        if ($teamId <= 0) {
            throw new HttpException(422, 'teamId is required');
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new HttpException(422, 'name is required');
        }

        $updated = $this->repo->updateTeam($mainId, $teamId, $name);
        if ($updated === null) {
            throw new HttpException(404, 'Team not found');
        }

        return $updated;
    }

    public function delete(array $params = [], array $query = [], array $body = []): array
    {
        $mainId = (int) ($query['main_id'] ?? 0);
        if ($mainId <= 0) {
            throw new HttpException(422, 'main_id is required');
        }

        $teamId = (int) ($params['teamId'] ?? 0);
        if ($teamId <= 0) {
            throw new HttpException(422, 'teamId is required');
        }

        $ok = $this->repo->deleteTeam($mainId, $teamId);
        if (!$ok) {
            throw new HttpException(404, 'Team not found');
        }

        return [
            'deleted' => true,
            'team_id' => $teamId,
        ];
    }
}
