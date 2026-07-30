<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\ApiController;
use App\Core\Response;
use App\Services\DashboardService;

/**
 * GET /dashboard - the BC agent's home screen figures.
 */
final class DashboardApiController extends ApiController
{
    public function index(): void
    {
        $service = new DashboardService();

        if ($this->bcId() !== null) {
            Response::ok($service->forBcAgent($this->bcId(), $this->userId()));
            return;
        }

        // Managers and above get the same shape, aggregated over their scope,
        // so one screen serves every role.
        Response::ok($service->forSupervisor($this->userId()));
    }
}
