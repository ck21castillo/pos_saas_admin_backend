<?php

declare(strict_types=1);

namespace PosAdmin\Controller;

use PosAdmin\Core\Database;
use PosAdmin\Core\Response;
use PosAdmin\Service\BusinessCategoryService;

final class AdminCategoriaNegocioController
{
    private BusinessCategoryService $service;

    public function __construct()
    {
        $this->service = new BusinessCategoryService();
    }

    public function list(): void
    {
        try {
            $pdo = Database::getConnection();
            Response::json([
                'ok' => true,
                'items' => $this->service->list($pdo),
                'catalogos' => $this->service->presentationCatalogs($pdo),
            ]);
        } catch (\Throwable $e) {
            $this->serverError($e);
        }
    }

    /** @param array<string,mixed> $body */
    public function create(array $body): void
    {
        $this->write(function () use ($body): array {
            $pdo = Database::getConnection();
            $pdo->beginTransaction();
            try {
                $item = $this->service->create($pdo, $body, $this->actorId(), $this->actorEmail());
                $pdo->commit();
                return ['item' => $item];
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }, 201);
    }

    /** @param array<string,mixed> $body */
    public function update(int $idCategory, array $body): void
    {
        $this->write(function () use ($idCategory, $body): array {
            $pdo = Database::getConnection();
            $pdo->beginTransaction();
            try {
                $item = $this->service->update($pdo, $idCategory, $body, $this->actorId(), $this->actorEmail());
                $pdo->commit();
                return ['item' => $item];
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        });
    }

    private function write(callable $callback, int $status = 200): void
    {
        try {
            Response::json(array_merge(['ok' => true], $callback()), $status);
        } catch (\InvalidArgumentException $e) {
            Response::json(['error' => 'VALIDATION', 'message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            $status = $e->getMessage() === 'CATEGORIA_NEGOCIO_NO_ENCONTRADA' ? 404 : 400;
            Response::json(['error' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            $this->serverError($e);
        }
    }

    private function serverError(\Throwable $e): void
    {
        $payload = ['error' => 'SERVER_ERROR'];
        if (($_ENV['APP_DEBUG'] ?? '0') === '1') {
            $payload['message'] = $e->getMessage();
        }
        Response::json($payload, 500);
    }

    private function actorId(): int
    {
        return (int)($_REQUEST['adminId'] ?? 0);
    }

    private function actorEmail(): string
    {
        return (string)($_REQUEST['adminEmail'] ?? '');
    }
}
