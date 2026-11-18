<?php
require __DIR__ . '/vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

// Usamos las variables de entorno definidas en el docker-compose.yml
$server   = getenv('MQTT_HOST') ?: 'mosquitto';
$port     = intval(getenv('MQTT_PORT') ?: 1883);
$clientId = 'coolguard_php_worker_' . uniqid();
$topic    = 'sensor/dht11';

// Conexión a MySQL usando el servicio definido en Docker
$db_host = getenv('MYSQL_HOST') ?: 'mysql';
$db_name = getenv('MYSQL_DB')   ?: 'coldguard';
$db_user = getenv('MYSQL_USER') ?: 'coldguard_user';
$db_pass = getenv('MYSQL_PASS') ?: 'coldguard_pass';

echo "[INIT] Conectando a MySQL en {$db_host}...\n";
$pdo = null;

// Reintentar conexión si MySQL tarda unos segundos más en arrancar al iniciar Docker
while (!$pdo) {
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        echo "[PHP-DB] ¡Conectado exitosamente a MySQL!\n";
    } catch (PDOException $e) {
        echo "[DB WAIT] Esperando a que MySQL esté listo...\n";
        sleep(3);
    }
}

// Crear tablas automáticamente si no existen
$pdo->exec("
    CREATE TABLE IF NOT EXISTS devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_uid VARCHAR(64) NOT NULL UNIQUE,
        location_name VARCHAR(100) DEFAULT 'Heladera Principal',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS telemetry (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id INT NOT NULL,
        temperature DECIMAL(5,2) NOT NULL,
        humidity DECIMAL(5,2) NOT NULL,
        device_uptime_s INT UNSIGNED DEFAULT 0,
        is_buffered BOOLEAN DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        inserted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
    );
");

// Configuración y conexión MQTT
$client = new MqttClient($server, $port, $clientId);
$connectionSettings = (new ConnectionSettings())
    ->setKeepAliveInterval(60)
    ->setReconnectAutomatically(true);

try {
    $client->connect($connectionSettings, true);
    echo "[MQTT] Conectado exitosamente al broker en {$server}:{$port}\n";
    echo "[MQTT] Escuchando el tópico: '{$topic}'...\n";

    // Suscripción al tópico que envía tu ESP32-S3
    $client->subscribe($topic, function (string $topic, string $message) use ($pdo) {
        echo "\n[MQTT EVENTO] Mensaje recibido en [{$topic}]: {$message}\n";

        $data = json_decode($message, true);

        if (!$data || !isset($data['temperature']) || !isset($data['humidity'])) {
            echo "[WARN] El payload no tiene el formato JSON esperado.\n";
            return;
        }

        $temperature = floatval($data['temperature']);
        $humidity    = floatval($data['humidity']);
        $uptime      = isset($data['time_uptime_s']) ? intval($data['time_uptime_s']) : 0;
        $isBuffered  = isset($data['buffered']) && $data['buffered'] === true ? 1 : 0;
        $device_uid  = $topic; 

        try {
            // Registrar o buscar el dispositivo
            $stmt = $pdo->prepare("SELECT id FROM devices WHERE device_uid = ?");
            $stmt->execute([$device_uid]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$device) {
                $stmtInsertDev = $pdo->prepare("INSERT INTO devices (device_uid, location_name) VALUES (?, ?)");
                $stmtInsertDev->execute([$device_uid, 'ESP32-S3 CoolGuard']);
                $deviceId = $pdo->lastInsertId();
            } else {
                $deviceId = $device['id'];
            }

            // Insertar telemetría
            $sql = "INSERT INTO telemetry (device_id, temperature, humidity, device_uptime_s, is_buffered, inserted_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())";
            $stmtInsertTel = $pdo->prepare($sql);
            $stmtInsertTel->execute([$deviceId, $temperature, $humidity, $uptime, $isBuffered]);

            echo "[DB OK] Guardado -> Temp: {$temperature}°C | Hum: {$humidity}% | Uptime: {$uptime}s | Buffer: {$isBuffered}\n";

        } catch (PDOException $e) {
            echo "[ERROR DB] " . $e->getMessage() . "\n";
        }

    }, 0);

    // Mantener el loop abierto escuchando permanentemente
    $client->loop(true);

} catch (Exception $e) {
    echo "[ERROR MQTT] " . $e->getMessage() . "\n";
}