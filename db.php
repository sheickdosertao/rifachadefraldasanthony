<?php
// db.php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);    

define('DB_HOST', 'mysql-18f5d867-financeironeosolar-238c.l.aivencloud.com');
define('DB_PORT', '21178');
define('DB_NAME', 'defaultdb');
define('DB_USER', 'avnadmin');
define('DB_PASS', 'AVNS_1f9v3z5MrYdbnQOd_X5');

function getPDO(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    
    // Configuração corrigida para ignorar a exigência restrita de arquivos locais de SSL
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (PDOException $e) {
        // Evita que o PHP quebre o Apache gerando o erro Bad Gateway
        die("Erro de conexão ao banco da Aiven: " . $e->getMessage());
    }
}

function initializeDatabase(PDO $pdo): void
{
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS tickets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ticket_number CHAR(3) NOT NULL UNIQUE,
                status ENUM('available', 'pending', 'paid') NOT NULL DEFAULT 'available',
                buyer_name VARCHAR(120) DEFAULT NULL,
                buyer_phone VARCHAR(30) DEFAULT NULL,
                created_at DATETIME DEFAULT NULL,
                INDEX idx_status (status),
                INDEX idx_buyer_phone (buyer_phone),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $count = (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();

        if ($count === 1000) {
            return;
        }

        if ($count === 0) {
            $pdo->beginTransaction();
            $sql = 'INSERT INTO tickets (ticket_number, status) VALUES ';
            $values = [];
            $placeholders = [];
            
            for ($number = 0; $number <= 999; $number++) {
                $placeholders[] = '(?, "available")';
                $values[] = sprintf('%03d', $number);
                
                if (count($placeholders) >= 300 || $number === 999) {
                    $stmt = $pdo->prepare($sql . implode(', ', $placeholders));
                    $stmt->execute($values);
                    $placeholders = [];
                    $values = [];
                }
            }
            $pdo->commit();
        }
    } catch (Exception $e) {
        // Impede travamentos durante a criação de tabelas
        echo "Aviso no banco: " . $e->getMessage();
    }
}

function releaseExpiredPending(PDO $pdo): void
{
    try {
        $stmt = $pdo->prepare(
            "UPDATE tickets
             SET status = 'available', buyer_name = NULL, buyer_phone = NULL, created_at = NULL
             WHERE status = 'pending' AND created_at IS NOT NULL AND created_at < (NOW() - INTERVAL 30 MINUTE)"
        );
        $stmt->execute();
    } catch (Exception $e) {
        // Ignora silenciosamente se falhar
    }
}