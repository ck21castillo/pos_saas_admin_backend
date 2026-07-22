<?php

declare(strict_types=1);

namespace PosAdmin\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class AdminSaasService
{
    private const ESTADOS = ['PRUEBA', 'ACTIVA', 'POR_VENCER', 'VENCIDA', 'SUSPENDIDA'];
    private const CICLOS = ['MENSUAL', 'ANUAL'];

    public function listPlans(PDO $pdo, bool $onlyActive = false): array
    {
        $where = $onlyActive ? 'WHERE activo = true' : '';
        $rows = $pdo->query("
            SELECT
                id_plan, codigo, nombre, descripcion,
                precio_mensual, precio_anual,
                usuarios_incluidos, precio_usuario_extra_mensual, precio_usuario_extra_anual,
                whatsapp_incluido, precio_whatsapp_mensual, precio_whatsapp_anual,
                visible_publico, activo, orden, created_at, updated_at
            FROM admin.saas_plan
            {$where}
            ORDER BY orden ASC, id_plan ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'normalizePlan'], $rows);
    }

    public function upsertPlan(PDO $pdo, array $body, ?int $idPlan = null): array
    {
        $codigo = $this->cleanCode($body['codigo'] ?? '');
        $nombre = $this->cleanText($body['nombre'] ?? '');
        if ($codigo === '' || $nombre === '') {
            throw new \InvalidArgumentException('PLAN_CODIGO_NOMBRE_REQUERIDOS');
        }

        $params = [
            ':codigo' => $codigo,
            ':nombre' => $nombre,
            ':descripcion' => $this->nullableText($body['descripcion'] ?? null),
            ':precio_mensual' => $this->money($body['precio_mensual'] ?? 0),
            ':precio_anual' => $this->money($body['precio_anual'] ?? 0),
            ':usuarios_incluidos' => max(1, (int)($body['usuarios_incluidos'] ?? 1)),
            ':precio_usuario_extra_mensual' => $this->money($body['precio_usuario_extra_mensual'] ?? 0),
            ':precio_usuario_extra_anual' => $this->money($body['precio_usuario_extra_anual'] ?? 0),
            ':whatsapp_incluido' => $this->boolParam($body['whatsapp_incluido'] ?? false),
            ':precio_whatsapp_mensual' => $this->money($body['precio_whatsapp_mensual'] ?? 0),
            ':precio_whatsapp_anual' => $this->money($body['precio_whatsapp_anual'] ?? 0),
            ':visible_publico' => $this->boolParam($body['visible_publico'] ?? true),
            ':activo' => $this->boolParam($body['activo'] ?? true),
            ':orden' => max(0, (int)($body['orden'] ?? 100)),
        ];

        if ($idPlan !== null) {
            $params[':id_plan'] = $idPlan;
            $st = $pdo->prepare('
                UPDATE admin.saas_plan
                SET codigo = :codigo,
                    nombre = :nombre,
                    descripcion = :descripcion,
                    precio_mensual = :precio_mensual,
                    precio_anual = :precio_anual,
                    usuarios_incluidos = :usuarios_incluidos,
                    precio_usuario_extra_mensual = :precio_usuario_extra_mensual,
                    precio_usuario_extra_anual = :precio_usuario_extra_anual,
                    whatsapp_incluido = CAST(:whatsapp_incluido AS boolean),
                    precio_whatsapp_mensual = :precio_whatsapp_mensual,
                    precio_whatsapp_anual = :precio_whatsapp_anual,
                    visible_publico = CAST(:visible_publico AS boolean),
                    activo = CAST(:activo AS boolean),
                    orden = :orden,
                    updated_at = now()
                WHERE id_plan = :id_plan
                RETURNING *
            ');
            $st->execute($params);
        } else {
            $st = $pdo->prepare('
                INSERT INTO admin.saas_plan (
                    codigo, nombre, descripcion,
                    precio_mensual, precio_anual,
                    usuarios_incluidos, precio_usuario_extra_mensual, precio_usuario_extra_anual,
                    whatsapp_incluido, precio_whatsapp_mensual, precio_whatsapp_anual,
                    visible_publico, activo, orden
                )
                VALUES (
                    :codigo, :nombre, :descripcion,
                    :precio_mensual, :precio_anual,
                    :usuarios_incluidos, :precio_usuario_extra_mensual, :precio_usuario_extra_anual,
                    CAST(:whatsapp_incluido AS boolean), :precio_whatsapp_mensual, :precio_whatsapp_anual,
                    CAST(:visible_publico AS boolean), CAST(:activo AS boolean), :orden
                )
                ON CONFLICT (codigo) DO UPDATE SET
                    nombre = EXCLUDED.nombre,
                    descripcion = EXCLUDED.descripcion,
                    precio_mensual = EXCLUDED.precio_mensual,
                    precio_anual = EXCLUDED.precio_anual,
                    usuarios_incluidos = EXCLUDED.usuarios_incluidos,
                    precio_usuario_extra_mensual = EXCLUDED.precio_usuario_extra_mensual,
                    precio_usuario_extra_anual = EXCLUDED.precio_usuario_extra_anual,
                    whatsapp_incluido = EXCLUDED.whatsapp_incluido,
                    precio_whatsapp_mensual = EXCLUDED.precio_whatsapp_mensual,
                    precio_whatsapp_anual = EXCLUDED.precio_whatsapp_anual,
                    visible_publico = EXCLUDED.visible_publico,
                    activo = EXCLUDED.activo,
                    orden = EXCLUDED.orden,
                    updated_at = now()
                RETURNING *
            ');
            $st->execute($params);
        }

        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('PLAN_NO_ENCONTRADO');
        }

        return $this->normalizePlan($row);
    }

    public function listPaymentChannels(PDO $pdo, bool $onlyActive = false): array
    {
        $where = $onlyActive ? 'WHERE activo = true' : '';
        $rows = $pdo->query("
            SELECT
                id_canal_pago AS id_canal,
                codigo,
                nombre,
                instrucciones AS descripcion,
                tipo,
                entidad,
                titular,
                numero_cuenta,
                activo,
                orden,
                created_at,
                updated_at
            FROM admin.saas_canal_pago
            {$where}
            ORDER BY orden ASC, id_canal_pago ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'normalizePaymentChannel'], $rows);
    }

    public function upsertPaymentChannel(PDO $pdo, array $body, ?int $idCanal = null): array
    {
        $codigo = $this->cleanCode($body['codigo'] ?? '');
        $nombre = $this->cleanText($body['nombre'] ?? '');
        if ($codigo === '' || $nombre === '') {
            throw new \InvalidArgumentException('CANAL_CODIGO_NOMBRE_REQUERIDOS');
        }
        $tipo = $this->paymentChannelType($body['tipo'] ?? $codigo);

        $params = [
            ':codigo' => $codigo,
            ':nombre' => $nombre,
            ':tipo' => $tipo,
            ':descripcion' => $this->nullableText($body['descripcion'] ?? null),
            ':visible_publico' => $this->boolParam($body['visible_publico'] ?? true),
            ':activo' => $this->boolParam($body['activo'] ?? true),
            ':orden' => max(0, (int)($body['orden'] ?? 100)),
        ];

        if ($idCanal !== null) {
            $params[':id_canal'] = $idCanal;
            $st = $pdo->prepare('
                UPDATE admin.saas_canal_pago
                SET codigo = :codigo,
                    nombre = :nombre,
                    tipo = :tipo,
                    instrucciones = :descripcion,
                    activo = CAST(:activo AS boolean),
                    orden = :orden,
                    updated_at = now()
                WHERE id_canal_pago = :id_canal
                RETURNING
                    id_canal_pago AS id_canal,
                    codigo,
                    nombre,
                    instrucciones AS descripcion,
                    tipo,
                    entidad,
                    titular,
                    numero_cuenta,
                    activo,
                    orden,
                    created_at,
                    updated_at
            ');
            $st->execute($params);
        } else {
            $st = $pdo->prepare('
                INSERT INTO admin.saas_canal_pago (codigo, nombre, tipo, instrucciones, activo, orden)
                VALUES (:codigo, :nombre, :tipo, :descripcion, CAST(:activo AS boolean), :orden)
                ON CONFLICT (codigo) DO UPDATE SET
                    nombre = EXCLUDED.nombre,
                    tipo = EXCLUDED.tipo,
                    instrucciones = EXCLUDED.instrucciones,
                    activo = EXCLUDED.activo,
                    orden = EXCLUDED.orden,
                    updated_at = now()
                RETURNING
                    id_canal_pago AS id_canal,
                    codigo,
                    nombre,
                    instrucciones AS descripcion,
                    tipo,
                    entidad,
                    titular,
                    numero_cuenta,
                    activo,
                    orden,
                    created_at,
                    updated_at
            ');
            $st->execute($params);
        }

        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('CANAL_NO_ENCONTRADO');
        }

        return $this->normalizePaymentChannel($row);
    }

    public function getCompanySubscription(PDO $pdo, int $idEmpresa): array
    {
        $empresa = $this->getCompany($pdo, $idEmpresa);
        $subscription = $this->getSubscription($pdo, $idEmpresa);
        $payments = $this->getRecentPayments($pdo, $idEmpresa);

        return [
            'empresa' => $empresa,
            'suscripcion' => $subscription,
            'pagos_recientes' => $payments,
            'planes' => $this->listPlans($pdo, true),
            'canales_pago' => $this->listPaymentChannels($pdo, true),
        ];
    }

    public function saveCompanySubscription(PDO $pdo, int $idEmpresa, array $body, int $actorId = 0, string $actorEmail = ''): array
    {
        $this->getCompany($pdo, $idEmpresa);
        $before = $this->getSubscription($pdo, $idEmpresa);

        $idPlan = (int)($body['id_plan'] ?? 0);
        $plan = $this->getPlan($pdo, $idPlan);

        $estado = strtoupper(trim((string)($body['estado'] ?? ($before['estado'] ?? 'ACTIVA'))));
        if (!in_array($estado, self::ESTADOS, true)) {
            throw new \InvalidArgumentException('ESTADO_SUSCRIPCION_INVALIDO');
        }

        $ciclo = strtoupper(trim((string)($body['ciclo'] ?? ($before['ciclo'] ?? 'MENSUAL'))));
        if (!in_array($ciclo, self::CICLOS, true)) {
            throw new \InvalidArgumentException('CICLO_SUSCRIPCION_INVALIDO');
        }

        $usuariosIncluidos = max(1, (int)($body['usuarios_incluidos'] ?? $plan['usuarios_incluidos']));
        $usuariosExtra = max(0, (int)($body['usuarios_extra'] ?? ($before['usuarios_extra'] ?? 0)));
        $whatsappActivo = $this->bool($body['whatsapp_activo'] ?? ($before['whatsapp_activo'] ?? $plan['whatsapp_incluido']));
        $descuento = $this->money($body['descuento_periodo'] ?? ($before['descuento_periodo'] ?? 0));
        $totals = $this->subscriptionTotals($plan, $ciclo, $usuariosExtra, $whatsappActivo, $descuento);

        $params = [
            ':id_empresa' => $idEmpresa,
            ':id_plan' => $idPlan,
            ':estado' => $estado,
            ':ciclo' => $ciclo,
            ':periodo_inicio' => $this->dateOrNull($body['periodo_inicio'] ?? ($before['periodo_inicio'] ?? null)),
            ':periodo_fin' => $this->dateOrNull($body['periodo_fin'] ?? ($before['periodo_fin'] ?? null)),
            ':proximo_pago_fecha' => $this->dateOrNull($body['proximo_pago_fecha'] ?? ($before['proximo_pago_fecha'] ?? null)),
            ':gracia_hasta' => $this->dateOrNull($body['gracia_hasta'] ?? ($before['gracia_hasta'] ?? null)),
            ':prueba_inicio' => $this->dateOrNull($body['prueba_inicio'] ?? ($before['prueba_inicio'] ?? null)),
            ':prueba_fin' => $this->dateOrNull($body['prueba_fin'] ?? ($before['prueba_fin'] ?? null)),
            ':usuarios_incluidos' => $usuariosIncluidos,
            ':usuarios_extra' => $usuariosExtra,
            ':whatsapp_activo' => $this->boolParam($whatsappActivo),
            ':valor_plan' => $totals['valor_plan'],
            ':valor_usuario_extra' => $totals['valor_usuario_extra'],
            ':valor_whatsapp' => $totals['valor_whatsapp'],
            ':descuento_periodo' => $descuento,
            ':total_periodo' => $totals['total_periodo'],
            ':notas' => $this->nullableText($body['notas'] ?? ($before['notas'] ?? null)),
        ];

        $st = $pdo->prepare('
            INSERT INTO admin.saas_suscripcion (
                id_empresa, id_plan, estado, ciclo,
                periodo_inicio, periodo_fin, proximo_pago_fecha, gracia_hasta,
                prueba_inicio, prueba_fin,
                usuarios_incluidos, usuarios_extra, whatsapp_activo,
                valor_plan, valor_usuario_extra, valor_whatsapp, descuento, total_periodo,
                notas
            )
            VALUES (
                :id_empresa, :id_plan, :estado, :ciclo,
                :periodo_inicio, :periodo_fin, :proximo_pago_fecha, :gracia_hasta,
                :prueba_inicio, :prueba_fin,
                :usuarios_incluidos, :usuarios_extra, CAST(:whatsapp_activo AS boolean),
                :valor_plan, :valor_usuario_extra, :valor_whatsapp, :descuento_periodo, :total_periodo,
                :notas
            )
            ON CONFLICT (id_empresa) DO UPDATE SET
                id_plan = EXCLUDED.id_plan,
                estado = EXCLUDED.estado,
                ciclo = EXCLUDED.ciclo,
                periodo_inicio = EXCLUDED.periodo_inicio,
                periodo_fin = EXCLUDED.periodo_fin,
                proximo_pago_fecha = EXCLUDED.proximo_pago_fecha,
                gracia_hasta = EXCLUDED.gracia_hasta,
                prueba_inicio = EXCLUDED.prueba_inicio,
                prueba_fin = EXCLUDED.prueba_fin,
                usuarios_incluidos = EXCLUDED.usuarios_incluidos,
                usuarios_extra = EXCLUDED.usuarios_extra,
                whatsapp_activo = EXCLUDED.whatsapp_activo,
                valor_plan = EXCLUDED.valor_plan,
                valor_usuario_extra = EXCLUDED.valor_usuario_extra,
                valor_whatsapp = EXCLUDED.valor_whatsapp,
                descuento = EXCLUDED.descuento,
                total_periodo = EXCLUDED.total_periodo,
                notas = EXCLUDED.notas,
                updated_at = now()
        ');
        $st->execute($params);

        $after = $this->getSubscription($pdo, $idEmpresa);
        $this->audit($pdo, $actorId, $actorEmail, 'SAAS_SUSCRIPCION_SAVE', 'empresa', $idEmpresa, $before, $after);

        return $after;
    }

    public function registerPayment(PDO $pdo, int $idEmpresa, array $body, int $actorId = 0, string $actorEmail = ''): array
    {
        $this->getCompany($pdo, $idEmpresa);
        $before = $this->getSubscription($pdo, $idEmpresa);
        $idPlan = (int)($body['id_plan'] ?? ($before['id_plan'] ?? 0));
        $plan = $this->getPlan($pdo, $idPlan);

        $ciclo = strtoupper(trim((string)($body['ciclo'] ?? ($before['ciclo'] ?? 'MENSUAL'))));
        if (!in_array($ciclo, self::CICLOS, true)) {
            throw new \InvalidArgumentException('CICLO_SUSCRIPCION_INVALIDO');
        }

        $fechaPago = $this->dateOrToday($body['fecha_pago'] ?? null);
        $periodoInicio = $this->dateOrToday($body['periodo_inicio'] ?? $fechaPago);
        $periodoFin = $this->dateOrNull($body['periodo_fin'] ?? null)
            ?: $this->periodEnd($periodoInicio, $ciclo);
        $graciaHasta = $this->graceUntil($periodoFin, $ciclo, $pdo);

        $usuariosExtra = max(0, (int)($body['usuarios_extra'] ?? ($before['usuarios_extra'] ?? 0)));
        $usuariosIncluidos = max(1, (int)($body['usuarios_incluidos'] ?? ($before['usuarios_incluidos'] ?? $plan['usuarios_incluidos'])));
        $whatsappActivo = $this->bool($body['whatsapp_activo'] ?? ($before['whatsapp_activo'] ?? $plan['whatsapp_incluido']));
        $descuento = $this->money($body['descuento_periodo'] ?? 0);
        $totals = $this->subscriptionTotals($plan, $ciclo, $usuariosExtra, $whatsappActivo, $descuento);
        $valorPagado = $this->money($body['valor_pagado'] ?? $totals['total_periodo']);

        if ($valorPagado <= 0) {
            throw new \InvalidArgumentException('VALOR_PAGADO_INVALIDO');
        }

        $referencia = $this->nullableText($body['referencia'] ?? null);
        $observaciones = $this->nullableText($body['observaciones'] ?? null);
        $canal = $this->cleanCode($body['canal_pago'] ?? 'TRANSFERENCIA');
        $canalInfo = $this->resolvePaymentChannel($pdo, $canal);
        $subtotalPago = round($totals['valor_plan'] + $totals['valor_usuario_extra'] + $totals['valor_whatsapp'], 2);

        $insert = $pdo->prepare('
            INSERT INTO admin.saas_pago (
                id_empresa, id_suscripcion, id_plan, ciclo, periodo_inicio, periodo_fin,
                fecha_pago, metodo_pago, id_canal_pago, referencia,
                usuarios_extra, whatsapp_activo, subtotal, descuento, impuesto, total,
                estado, notas, registrado_por
            )
            VALUES (
                :id_empresa, :id_suscripcion, :id_plan, :ciclo, :periodo_inicio, :periodo_fin,
                :fecha_pago, :metodo_pago, :id_canal_pago, :referencia,
                :usuarios_extra, CAST(:whatsapp_activo AS boolean), :subtotal, :descuento, 0, :total,
                :estado_pago, :notas, :registrado_por
            )
            RETURNING
                *,
                total AS valor_pagado,
                metodo_pago AS canal_pago,
                notas AS observaciones
        ');
        $insert->execute([
            ':id_empresa' => $idEmpresa,
            ':id_suscripcion' => $before['id_suscripcion'] ?? null,
            ':id_plan' => $idPlan,
            ':ciclo' => $ciclo,
            ':periodo_inicio' => $periodoInicio,
            ':periodo_fin' => $periodoFin,
            ':fecha_pago' => $fechaPago,
            ':metodo_pago' => $canalInfo['metodo_pago'],
            ':id_canal_pago' => $canalInfo['id_canal_pago'],
            ':referencia' => $referencia,
            ':usuarios_extra' => $usuariosExtra,
            ':whatsapp_activo' => $this->boolParam($whatsappActivo),
            ':subtotal' => $subtotalPago,
            ':descuento' => $descuento,
            ':total' => $valorPagado,
            ':notas' => $observaciones,
            ':registrado_por' => $actorId ?: null,
        ]);
        $payment = $insert->fetch(PDO::FETCH_ASSOC);

        $this->saveCompanySubscription($pdo, $idEmpresa, [
            'id_plan' => $idPlan,
            'estado' => 'ACTIVA',
            'ciclo' => $ciclo,
            'periodo_inicio' => $periodoInicio,
            'periodo_fin' => $periodoFin,
            'proximo_pago_fecha' => $periodoFin,
            'gracia_hasta' => $graciaHasta,
            'usuarios_incluidos' => $usuariosIncluidos,
            'usuarios_extra' => $usuariosExtra,
            'whatsapp_activo' => $whatsappActivo,
            'descuento_periodo' => $descuento,
            'notas' => $body['notas'] ?? ($before['notas'] ?? null),
        ], $actorId, $actorEmail);

        $this->setCompanyActive($pdo, $idEmpresa, true);
        $after = $this->getSubscription($pdo, $idEmpresa);
        $this->audit($pdo, $actorId, $actorEmail, 'SAAS_PAGO_REGISTRADO', 'empresa', $idEmpresa, $before, [
            'suscripcion' => $after,
            'pago' => $payment,
        ]);

        return [
            'suscripcion' => $after,
            'pago' => $this->normalizePayment((array)$payment),
        ];
    }

    public function suspendCompany(PDO $pdo, int $idEmpresa, string $motivo, int $actorId = 0, string $actorEmail = ''): array
    {
        $this->getCompany($pdo, $idEmpresa);
        $before = $this->getSubscription($pdo, $idEmpresa);

        $this->ensureSubscriptionExists($pdo, $idEmpresa);
        $st = $pdo->prepare("
            UPDATE admin.saas_suscripcion
            SET estado = 'SUSPENDIDA',
                suspendida_at = now(),
                suspendida_por = :actor_id,
                suspendida_motivo = :motivo,
                updated_at = now()
            WHERE id_empresa = :id_empresa
        ");
        $st->execute([
            ':id_empresa' => $idEmpresa,
            ':actor_id' => $actorId ?: null,
            ':motivo' => $this->nullableText($motivo) ?: 'Suspension manual desde panel administrativo',
        ]);

        $this->setCompanyActive($pdo, $idEmpresa, false);
        $after = $this->getSubscription($pdo, $idEmpresa);
        $this->audit($pdo, $actorId, $actorEmail, 'SAAS_EMPRESA_SUSPENDIDA', 'empresa', $idEmpresa, $before, $after);

        return $after;
    }

    public function reactivateCompany(PDO $pdo, int $idEmpresa, int $actorId = 0, string $actorEmail = ''): array
    {
        $this->getCompany($pdo, $idEmpresa);
        $before = $this->getSubscription($pdo, $idEmpresa);

        $this->ensureSubscriptionExists($pdo, $idEmpresa);
        $st = $pdo->prepare("
            UPDATE admin.saas_suscripcion
            SET estado = CASE
                    WHEN proximo_pago_fecha IS NOT NULL AND proximo_pago_fecha < CURRENT_DATE THEN 'VENCIDA'
                    ELSE 'ACTIVA'
                END,
                suspendida_at = NULL,
                suspendida_por = NULL,
                suspendida_motivo = NULL,
                updated_at = now()
            WHERE id_empresa = :id_empresa
        ");
        $st->execute([':id_empresa' => $idEmpresa]);

        $this->setCompanyActive($pdo, $idEmpresa, true);
        $after = $this->getSubscription($pdo, $idEmpresa);
        $this->audit($pdo, $actorId, $actorEmail, 'SAAS_EMPRESA_REACTIVADA', 'empresa', $idEmpresa, $before, $after);

        return $after;
    }

    public function extendTrial(PDO $pdo, int $idEmpresa, array $body, int $actorId = 0, string $actorEmail = ''): array
    {
        $this->getCompany($pdo, $idEmpresa);
        $before = $this->getSubscription($pdo, $idEmpresa);
        $this->ensureSubscriptionExists($pdo, $idEmpresa);

        $days = max(1, min(90, (int)($body['dias'] ?? 2)));
        $from = $this->dateOrToday($body['desde'] ?? null);
        $until = (new DateTimeImmutable($from))->add(new DateInterval('P' . $days . 'D'))->format('Y-m-d');

        $st = $pdo->prepare("
            UPDATE admin.saas_suscripcion
            SET estado = 'PRUEBA',
                prueba_inicio = :desde,
                prueba_fin = :hasta,
                proximo_pago_fecha = :hasta,
                gracia_hasta = NULL,
                suspendida_at = NULL,
                suspendida_por = NULL,
                suspendida_motivo = NULL,
                updated_at = now()
            WHERE id_empresa = :id_empresa
        ");
        $st->execute([
            ':id_empresa' => $idEmpresa,
            ':desde' => $from,
            ':hasta' => $until,
        ]);

        $this->setCompanyActive($pdo, $idEmpresa, true);
        $after = $this->getSubscription($pdo, $idEmpresa);
        $this->audit($pdo, $actorId, $actorEmail, 'SAAS_PRUEBA_EXTENDIDA', 'empresa', $idEmpresa, $before, $after);

        return $after;
    }

    private function getCompany(PDO $pdo, int $idEmpresa): array
    {
        $st = $pdo->prepare('
            SELECT
                e.id_empresa, e.nombre, e.codigo, e.nit, e.estado,
                e.tipo_negocio, e.created_at, e.updated_at,
                t.db_name, t.db_user, t.estado AS tenant_estado
            FROM pos_saas.empresa e
            LEFT JOIN admin.tenant_database t ON t.id_empresa = e.id_empresa
            WHERE e.id_empresa = :id
            LIMIT 1
        ');
        $st->execute([':id' => $idEmpresa]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('EMPRESA_NO_ENCONTRADA');
        }
        return $row;
    }

    private function getSubscription(PDO $pdo, int $idEmpresa): ?array
    {
        $st = $pdo->prepare('
            SELECT
                v.*,
                s.id_plan,
                s.prueba_inicio, s.prueba_fin, s.periodo_inicio, s.periodo_fin,
                s.gracia_hasta, s.usuarios_incluidos, s.usuarios_extra,
                s.whatsapp_activo, s.valor_plan, s.valor_usuario_extra,
                s.valor_whatsapp, s.descuento AS descuento_periodo, s.total_periodo,
                s.suspendida_at AS suspendida_en, s.suspendida_motivo, s.notas
            FROM admin.v_saas_empresa_estado v
            LEFT JOIN admin.saas_suscripcion s ON s.id_empresa = v.id_empresa
            WHERE v.id_empresa = :id
            LIMIT 1
        ');
        $st->execute([':id' => $idEmpresa]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->normalizeSubscription($row) : null;
    }

    private function getRecentPayments(PDO $pdo, int $idEmpresa): array
    {
        $st = $pdo->prepare('
            SELECT
                p.*,
                p.total AS valor_pagado,
                COALESCE(c.codigo, p.metodo_pago) AS canal_pago,
                p.notas AS observaciones,
                pl.codigo AS plan_codigo,
                pl.nombre AS plan_nombre
            FROM admin.saas_pago p
            LEFT JOIN admin.saas_plan pl ON pl.id_plan = p.id_plan
            LEFT JOIN admin.saas_canal_pago c ON c.id_canal_pago = p.id_canal_pago
            WHERE p.id_empresa = :id
            ORDER BY p.fecha_pago DESC, p.id_pago DESC
            LIMIT 20
        ');
        $st->execute([':id' => $idEmpresa]);
        return array_map([$this, 'normalizePayment'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function getPlan(PDO $pdo, int $idPlan): array
    {
        if ($idPlan <= 0) {
            throw new \InvalidArgumentException('PLAN_REQUERIDO');
        }

        $st = $pdo->prepare('SELECT * FROM admin.saas_plan WHERE id_plan = :id LIMIT 1');
        $st->execute([':id' => $idPlan]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('PLAN_NO_ENCONTRADO');
        }

        return $this->normalizePlan($row);
    }

    private function ensureSubscriptionExists(PDO $pdo, int $idEmpresa): void
    {
        $st = $pdo->prepare('SELECT 1 FROM admin.saas_suscripcion WHERE id_empresa = :id LIMIT 1');
        $st->execute([':id' => $idEmpresa]);
        if (!$st->fetchColumn()) {
            throw new \RuntimeException('SUSCRIPCION_NO_ENCONTRADA');
        }
    }

    private function setCompanyActive(PDO $pdo, int $idEmpresa, bool $active): void
    {
        $estado = $active ? 1 : 0;
        $st = $pdo->prepare('
            UPDATE pos_saas.empresa
            SET estado = :estado, updated_at = now()
            WHERE id_empresa = :id
        ');
        $st->execute([':estado' => $estado, ':id' => $idEmpresa]);

        if ($this->tableExists($pdo, 'admin.tenant_database')) {
            $tenantEstado = $active ? 'ACTIVE' : 'SUSPENDED';
            $tenant = $pdo->prepare('
                UPDATE admin.tenant_database
                SET estado = :estado, updated_at = now()
                WHERE id_empresa = :id
            ');
            $tenant->execute([':estado' => $tenantEstado, ':id' => $idEmpresa]);
        }
    }

    private function subscriptionTotals(array $plan, string $ciclo, int $usuariosExtra, bool $whatsappActivo, float $descuento): array
    {
        $isAnnual = $ciclo === 'ANUAL';
        $valorPlan = (float)$plan[$isAnnual ? 'precio_anual' : 'precio_mensual'];
        $valorUsuarioExtra = $usuariosExtra * (float)$plan[$isAnnual ? 'precio_usuario_extra_anual' : 'precio_usuario_extra_mensual'];
        $valorWhatsapp = 0.0;
        if ($whatsappActivo && !$this->bool($plan['whatsapp_incluido'] ?? false)) {
            $valorWhatsapp = (float)$plan[$isAnnual ? 'precio_whatsapp_anual' : 'precio_whatsapp_mensual'];
        }

        return [
            'valor_plan' => round($valorPlan, 2),
            'valor_usuario_extra' => round($valorUsuarioExtra, 2),
            'valor_whatsapp' => round($valorWhatsapp, 2),
            'total_periodo' => round(max(0, $valorPlan + $valorUsuarioExtra + $valorWhatsapp - $descuento), 2),
        ];
    }

    private function periodEnd(string $periodoInicio, string $ciclo): string
    {
        $start = new DateTimeImmutable($periodoInicio);
        $end = $ciclo === 'ANUAL'
            ? $start->add(new DateInterval('P1Y'))->sub(new DateInterval('P1D'))
            : $start->add(new DateInterval('P1M'))->sub(new DateInterval('P1D'));

        return $end->format('Y-m-d');
    }

    private function graceUntil(string $periodoFin, string $ciclo, PDO $pdo): string
    {
        $key = $ciclo === 'ANUAL' ? 'GRACIA_ANUAL_DIAS' : 'GRACIA_MENSUAL_DIAS';
        $days = $this->configInt($pdo, $key, $ciclo === 'ANUAL' ? 10 : 7);
        return (new DateTimeImmutable($periodoFin))
            ->add(new DateInterval('P' . max(0, $days) . 'D'))
            ->format('Y-m-d');
    }

    private function configInt(PDO $pdo, string $clave, int $default): int
    {
        $st = $pdo->prepare('SELECT valor FROM admin.saas_configuracion WHERE clave = :clave LIMIT 1');
        $st->execute([':clave' => $clave]);
        $value = $st->fetchColumn();
        return $value === false ? $default : (int)$value;
    }

    private function audit(PDO $pdo, int $actorId, string $actorEmail, string $action, string $targetType, int $targetId, mixed $before, mixed $after): void
    {
        if (!$this->tableExists($pdo, 'admin.audit_log')) {
            return;
        }

        $st = $pdo->prepare('
            INSERT INTO admin.audit_log (
                actor_id, actor_email, action, target_type, target_id, before, after, ip, user_agent
            )
            VALUES (
                :actor_id, :actor_email, :action, :target_type, :target_id,
                :before::jsonb, :after::jsonb, :ip, :user_agent
            )
        ');
        $st->execute([
            ':actor_id' => $actorId ?: null,
            ':actor_email' => $actorEmail ?: null,
            ':action' => $action,
            ':target_type' => $targetType,
            ':target_id' => $targetId,
            ':before' => json_encode($before, JSON_UNESCAPED_UNICODE),
            ':after' => json_encode($after, JSON_UNESCAPED_UNICODE),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    private function tableExists(PDO $pdo, string $qualifiedName): bool
    {
        $st = $pdo->prepare('SELECT to_regclass(:name) IS NOT NULL');
        $st->execute([':name' => $qualifiedName]);
        return (bool)$st->fetchColumn();
    }

    private function normalizePlan(array $row): array
    {
        foreach ([
            'precio_mensual', 'precio_anual',
            'precio_usuario_extra_mensual', 'precio_usuario_extra_anual',
            'precio_whatsapp_mensual', 'precio_whatsapp_anual',
        ] as $key) {
            $row[$key] = (float)($row[$key] ?? 0);
        }
        $row['id_plan'] = (int)$row['id_plan'];
        $row['usuarios_incluidos'] = (int)$row['usuarios_incluidos'];
        $row['orden'] = (int)$row['orden'];
        $row['whatsapp_incluido'] = $this->bool($row['whatsapp_incluido'] ?? false);
        $row['visible_publico'] = $this->bool($row['visible_publico'] ?? true);
        $row['activo'] = $this->bool($row['activo'] ?? false);
        return $row;
    }

    private function normalizePaymentChannel(array $row): array
    {
        if (!array_key_exists('id_canal', $row) && array_key_exists('id_canal_pago', $row)) {
            $row['id_canal'] = $row['id_canal_pago'];
        }
        if (!array_key_exists('descripcion', $row) && array_key_exists('instrucciones', $row)) {
            $row['descripcion'] = $row['instrucciones'];
        }
        $row['id_canal'] = (int)$row['id_canal'];
        $row['orden'] = (int)$row['orden'];
        $row['activo'] = $this->bool($row['activo'] ?? false);
        return $row;
    }

    private function normalizeSubscription(array $row): array
    {
        foreach ([
            'id_empresa', 'id_suscripcion', 'id_plan', 'usuarios_incluidos',
            'usuarios_extra', 'dias_para_vencer', 'dias_para_pago', 'dias_gracia_restantes',
            'empresa_estado',
        ] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int)$row[$key];
            }
        }
        foreach ([
            'valor_plan', 'valor_usuario_extra', 'valor_whatsapp',
            'descuento_periodo', 'total_periodo', 'ultimo_pago_total',
        ] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (float)$row[$key];
            }
        }
        foreach (['whatsapp_activo', 'whatsapp_incluido', 'en_gracia'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $this->bool($row[$key] ?? false);
            }
        }
        return $row;
    }

    private function normalizePayment(array $row): array
    {
        foreach (['id_pago', 'id_empresa', 'id_suscripcion', 'id_plan', 'id_canal_pago', 'registrado_por'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int)$row[$key];
            }
        }
        if (!array_key_exists('valor_pagado', $row) && array_key_exists('total', $row)) {
            $row['valor_pagado'] = $row['total'];
        }
        if (!array_key_exists('canal_pago', $row) && array_key_exists('metodo_pago', $row)) {
            $row['canal_pago'] = $row['metodo_pago'];
        }
        if (!array_key_exists('observaciones', $row) && array_key_exists('notas', $row)) {
            $row['observaciones'] = $row['notas'];
        }
        if (array_key_exists('valor_pagado', $row)) {
            $row['valor_pagado'] = (float)$row['valor_pagado'];
        }
        foreach (['subtotal', 'descuento', 'impuesto', 'total'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (float)$row[$key];
            }
        }
        return $row;
    }

    private function resolvePaymentChannel(PDO $pdo, string $codigo): array
    {
        $codigo = $this->cleanCode($codigo) ?: 'TRANSFERENCIA';
        $st = $pdo->prepare('
            SELECT id_canal_pago, codigo, tipo
            FROM admin.saas_canal_pago
            WHERE codigo = :codigo
            LIMIT 1
        ');
        $st->execute([':codigo' => $codigo]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (is_array($row)) {
            return [
                'id_canal_pago' => (int)$row['id_canal_pago'],
                'metodo_pago' => $this->paymentMethodFromChannel((string)$row['codigo'], (string)$row['tipo']),
            ];
        }

        return [
            'id_canal_pago' => null,
            'metodo_pago' => $this->paymentMethodFromChannel($codigo, $codigo),
        ];
    }

    private function paymentMethodFromChannel(string $codigo, string $tipo = ''): string
    {
        $codigo = $this->cleanCode($codigo);
        $allowed = ['EFECTIVO', 'TRANSFERENCIA', 'TARJETA', 'NEQUI', 'DAVIPLATA', 'PSE', 'OTRO'];
        if (in_array($codigo, $allowed, true)) {
            return $codigo;
        }

        $tipo = $this->paymentChannelType($tipo ?: $codigo);
        if ($tipo === 'BANCO') {
            return 'TRANSFERENCIA';
        }

        return in_array($tipo, $allowed, true) ? $tipo : 'OTRO';
    }

    private function paymentChannelType(mixed $value): string
    {
        $type = $this->cleanCode($value);
        if ($type === 'TRANSFERENCIA') {
            return 'BANCO';
        }

        $allowed = ['EFECTIVO', 'BANCO', 'NEQUI', 'DAVIPLATA', 'PSE', 'TARJETA', 'OTRO'];
        return in_array($type, $allowed, true) ? $type : 'OTRO';
    }

    private function cleanCode(mixed $value): string
    {
        $code = strtoupper(trim((string)$value));
        return preg_replace('/[^A-Z0-9_]/', '', $code) ?? '';
    }

    private function cleanText(mixed $value): string
    {
        return trim((string)$value);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function money(mixed $value): float
    {
        if (is_string($value)) {
            $value = trim(str_replace(['$', ' '], '', $value));
            if (str_contains($value, ',') && str_contains($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } elseif (str_contains($value, ',')) {
                $value = str_replace(',', '.', $value);
            }
        }
        $number = (float)$value;
        if (!is_finite($number)) {
            return 0.0;
        }
        return round($number, 2);
    }

    private function dateOrNull(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return null;
        }
        return (new DateTimeImmutable($text))->format('Y-m-d');
    }

    private function dateOrToday(mixed $value): string
    {
        return $this->dateOrNull($value)
            ?: (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y-m-d');
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        $text = strtolower(trim((string)$value));
        return in_array($text, ['1', 't', 'true', 'yes', 'si', 'on'], true);
    }

    private function boolParam(mixed $value): string
    {
        return $this->bool($value) ? 'true' : 'false';
    }
}


