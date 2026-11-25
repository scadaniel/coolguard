<?php
/**
 * Worker MQTT - CoolGuard IoT Cloud
 * Este script corre en segundo plano dentro de un contenedor Docker, se conecta
 * al broker MQTT para escuchar las telemetrías del ESP32-S3 y las guarda en MySQL.
 */

// Configurar zona horaria local (Argentina UTC-3)
date_default_timezone_set('America/Argentina/Buenos_Aires');

// 1. Cargar el autoloader de Composer para importar las librerías externas (como php-mqtt/client)
require __DIR__ . '/vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

// 2. Configuración de conexión MQTT utilizando variables de entorno de Docker
$server   = getenv('MQTT_HOST') ?: 'mosquitto'; // Nombre del servicio Mosquitto en Docker Compose
$port     = intval(getenv('MQTT_PORT') ?: 1883); // Puerto estándar MQTT
$clientId = 'coolguard_php_worker_' . uniqid(); // ID único para evitar conflictos en el broker
$topic    = 'sensor/dht11';                     // Tópico donde el ESP32 publica los datos

// 3. Configuración de conexión a la base de datos MySQL (definidas en Docker Compose)
$db_host = getenv('MYSQL_HOST') ?: 'mysql';
$db_name = getenv('MYSQL_DB')   ?: 'coldguard';
$db_user = getenv('MYSQL_USER') ?: 'coldguard_user';
$db_pass = getenv('MYSQL_PASS') ?: 'coldguard_pass';

echo "[INIT] Conectando a MySQL en {$db_host}...\n";
$pdo = null;

/**
 * 4. Bucle de reintento para la conexión a MySQL
 * Al iniciar Docker, MySQL tarda unos segundos en levantar su motor interno.
 * Este bucle evita que el script falle por completo si la base de datos aún no está lista.
 */
while (!$pdo) {
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        // Fijar zona horaria de la sesión MySQL a UTC-3
        $pdo->exec("SET time_zone = '-03:00'");
        echo "[PHP-DB] ¡Conectado exitosamente a MySQL (UTC-3)!\n";
    } catch (PDOException $e) {
        echo "[DB WAIT] Esperando a que MySQL esté listo...\n";
        sleep(3);
    }
}

/**
 * 5. Inicialización automática de la estructura de tablas
 * Si las tablas 'devices' y 'telemetry' no existen en la base de datos, 
 * el script las crea de forma automática para garantizar la integridad referencial.
 */
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

// 6. Instanciación del cliente MQTT y configuración de parámetros avanzados
$client = new MqttClient($server, $port, $clientId);
$connectionSettings = (new ConnectionSettings())
    ->setKeepAliveInterval(60)              // Intervalo de latencia (ping) para mantener la sesión viva
    ->setReconnectAutomatically(true);       // Habilita la reconexión automática ante cortes de red

try {
    /**
     * IMPORTANTE: Al activar 'setReconnectAutomatically(true)', el segundo parámetro 
     * de connect() (clean session) DEBE ser 'false' para evitar conflictos en la librería.
     */
    $client->connect($connectionSettings, false);
    echo "[MQTT] Conectado exitosamente al broker en {$server}:{$port}\n";
    echo "[MQTT] Escuchando el tópico: '{$topic}'...\n";

    /**
     * 7. Suscripción al canal de telemetría del ESP32-S3
     * Cada vez que el ESP32 envíe un mensaje a 'sensor/dht11', se ejecutará esta función anónima.
     */
    $client->subscribe($topic, function (string $topic, string $message) use ($pdo) {
        echo "\n[MQTT EVENTO] Mensaje recibido en [{$topic}]: {$message}\n";

        // Decodificar el JSON enviado por el microcontrolador
        $data = json_decode($message, true);

        // Validar que el paquete contenga los campos mínimos necesarios
        if (!$data || !isset($data['temperature']) || !isset($data['humidity'])) {
            echo "[WARN] El payload no tiene el formato JSON esperado.\n";
            return;
        }

        // Extraer y castear los valores del sensor
        $temperature = floatval($data['temperature']);
        $humidity    = floatval($data['humidity']);
        $uptime      = isset($data['time_uptime_s']) ? intval($data['time_uptime_s']) : 0;
        $isBuffered  = isset($data['buffered']) && $data['buffered'] === true ? 1 : 0;
        $device_uid  = $topic; // Usamos el tópico como identificador lógico del dispositivo

        try {
            // 8. Verificar si el dispositivo ya está registrado en la base de datos
            $stmt = $pdo->prepare("SELECT id FROM devices WHERE device_uid = ?");
            $stmt->execute([$device_uid]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$device) {
                // Si no existe, lo registramos automáticamente
                $stmtInsertDev = $pdo->prepare("INSERT INTO devices (device_uid, location_name) VALUES (?, ?)");
                $stmtInsertDev->execute([$device_uid, 'ESP32-S3 CoolGuard']);
                $deviceId = $pdo->lastInsertId();
            } else {
                $deviceId = $device['id'];
            }

            // Obtener la hora local exacta de Argentina (UTC-3)
            $insertedAt = (new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                ->format('Y-m-d H:i:s');

            // 9. Insertar el registro de telemetría en la tabla correspondiente
            $sql = "INSERT INTO telemetry (device_id, temperature, humidity, device_uptime_s, is_buffered, inserted_at) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmtInsertTel = $pdo->prepare($sql);
            $stmtInsertTel->execute([$deviceId, $temperature, $humidity, $uptime, $isBuffered, $insertedAt]);

            echo "[DB OK] [{$insertedAt}] Guardado -> Temp: {$temperature}°C | Hum: {$humidity}% | Uptime: {$uptime}s | Buffer: {$isBuffered}\n";

        } catch (PDOException $e) {
            echo "[ERROR DB] " . $e->getMessage() . "\n";
        }

    }, 0); // QoS 0 para entrega rápida

    // 10. Mantener el proceso en ejecución continua escuchando los mensajes entrantes
    $client->loop(true);

} catch (Exception $e) {
    echo "[ERROR MQTT] " . $e->getMessage() . "\n";
}