<?php
$db_host = getenv('MYSQL_HOST') ?: 'mysql';
$db_name = getenv('MYSQL_DB')   ?: 'coldguard';
$db_user = getenv('MYSQL_USER') ?: 'coldguard_user';
$db_pass = getenv('MYSQL_PASS') ?: 'coldguard_pass';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $stmt = $pdo->query("
        SELECT t.*, d.device_uid, d.location_name 
        FROM telemetry t 
        JOIN devices d ON t.device_id = d.id 
        ORDER BY t.id DESC 
        LIMIT 20
    ");
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $latest = count($registros) > 0 ? $registros[0] : null;

} catch (PDOException $e) {
    $registros = [];
    $latest = null;
    $db_error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CoolGuard IoT - Cloud Dashboard</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1000px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        h1 { color: #007bff; margin-top: 0; display: flex; align-items: center; gap: 10px; }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .card { background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 5px solid #007bff; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .card.temp { border-left-color: #ffc107; }
        .card.hum { border-left-color: #17a2b8; }
        .card h3 { margin: 0 0 10px 0; font-size: 14px; color: #6c757d; text-transform: uppercase; }
        .card .value { font-size: 28px; font-weight: bold; color: #212529; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #dee2e6; font-size: 14px; }
        th { background-color: #007bff; color: white; }
        tr:hover { background-color: #f1f3f5; }
        .badge { background: #ffc107; color: #212529; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge-normal { background: #28a745; color: white; }
    </style>
</head>
<body>

<div class="container">
    <h1>❄️ CoolGuard IoT Cloud Dashboard</h1>
    <p>Monitoreo en tiempo real del sistema de refrigeración (ESP32-S3 + MQTT).</p>

    <?php if (isset($db_error)): ?>
        <p style="color: red;"><b>Aviso DB:</b> Conectando a la base de datos... Si recién enciendes Docker, recarga en unos segundos.</p>
    <?php endif; ?>

    <?php if ($latest): ?>
        <div class="cards">
            <div class="card temp">
                <h3>Temperatura Actual</h3>
                <div class="value"><?= htmlspecialchars($latest['temperature']) ?> °C</div>
            </div>
            <div class="card hum">
                <h3>Humedad Relativa</h3>
                <div class="value"><?= htmlspecialchars($latest['humidity']) ?> %</div>
            </div>
            <div class="card">
                <h3>Tópico MQTT</h3>
                <div class="value" style="font-size: 16px; padding-top: 5px;"><?= htmlspecialchars($latest['device_uid']) ?></div>
            </div>
        </div>
    <?php else: ?>
        <p><i>Esperando el primer reporte de datos desde el ESP32-S3 al broker MQTT...</i></p>
    <?php endif; ?>

    <h2>📋 Historial de Registros</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Tópico / Dispositivo</th>
                <th>Temperatura</th>
                <th>Humedad</th>
                <th>Uptime ESP32</th>
                <th>Origen</th>
                <th>Fecha Ingreso (DB)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($registros) > 0): ?>
                <?php foreach ($registros as $row): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td><strong><?= htmlspecialchars($row['device_uid']) ?></strong></td>
                        <td><?= $row['temperature'] ?> °C</td>
                        <td><?= $row['humidity'] ?> %</td>
                        <td><?= $row['device_uptime_s'] ?> s</td>
                        <td>
                            <?php if ($row['is_buffered']): ?>
                                <span class="badge" title="Recuperado de memoria NVS por corte de red">NVS Buffer</span>
                            <?php else: ?>
                                <span class="badge badge-normal">Tiempo Real</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $row['inserted_at'] ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" style="text-align: center;">No hay registros disponibles todavía.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>