<?php
class DB {
  private static ?mysqli $conn = null;

  public static function conn(?string $dbName = null): mysqli {
    if (self::$conn instanceof mysqli) {
      if ($dbName) {
        self::$conn->select_db($dbName);
      }
      return self::$conn;
    }

    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $port = (int)($_ENV['DB_PORT'] ?? 3306);
    $user = $_ENV['DB_USER'] ?? '';
    $pass = $_ENV['DB_PASS'] ?? '';

    $defaultDb = $_ENV['MOVIRA_CORE_SCHEMA'] ?? '';
    if ($user === '' || $defaultDb === '') {
      error_log('DB config missing: DB_USER and MOVIRA_CORE_SCHEMA must be set in .env');
    }
    $conn = new mysqli($host, $user, $pass, $defaultDb, $port);

    if ($conn->connect_error) {
      http_response_code(500);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode([
        'success' => false,
        'data' => null,
        'meta' => null,
        'error' => [
          'code' => 'ERR_COMMON_005',
          'message' => 'Database connection failed',
          'http_status' => 500,
          'details' => [],
          'request_id' => '00000000-0000-7000-8000-000000000000',
        ],
      ]);
      exit;
    }

    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+07:00'");

    self::$conn = $conn;
    return self::$conn;
  }

  public static function query(string $sql, array $params = []): mysqli_result|bool {
    $conn = self::conn();

    if (empty($params)) {
      $result = $conn->query($sql);
      if ($result === false) {
        throw new Exception("Query error: " . $conn->error);
      }
      return $result;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      throw new Exception("Prepare failed: " . $conn->error);
    }

    $types = '';
    foreach ($params as $p) {
      $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
    }

    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
      throw new Exception("Execute failed: " . $stmt->error);
    }

    $res = $stmt->get_result();
    return $res ?: true;
  }
}
?>
