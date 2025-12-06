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

    $host = '100.81.62.41';
    $port = 3306;
    $user = 'movira_dev';
    $pass = 'devjayaA9&';

    // connect default ke movira_core_dev
    $defaultDb = $dbName ?: 'movira_core_dev';
    $conn = new mysqli($host, $user, $pass, $defaultDb, $port);

    if ($conn->connect_error) {
      die('Database connection failed: ' . $conn->connect_error);
    }

    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+07:00'");

    self::$conn = $conn;
    return self::$conn;
  }

  public static function query(string $sql, array $params = []): mysqli_result|bool {
    $conn = self::conn();

    // Kalau tanpa parameter, langsung jalan
    if (empty($params)) {
      $result = $conn->query($sql);
      if ($result === false) {
        throw new Exception("Query error: " . $conn->error);
      }
      return $result;
    }

    // Kalau ada parameter → pakai prepared statement
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
    return $res ?: true; // kalau INSERT/UPDATE ga punya result
  }
}
?>