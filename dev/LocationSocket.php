<?php

namespace RakiWS;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class LocationSocket implements MessageComponentInterface
{
    protected array $abangOnline = [];

    // Stock cache: sessionId → ['data' => [...], 'cachedAt' => timestamp]
    protected array $stockCache = [];
    protected int   $stockCacheTtl = 30; // seconds

    protected \mysqli $db;
    protected string  $schema;

    public function __construct(\mysqli $db, string $schema = 'raki_dev')
    {
        $this->db     = $db;
        $this->schema = $schema;
    }

    public function onOpen(ConnectionInterface $conn)
    {
        echo "Connected: {$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);
        if (!$data) return;

        $type = $data['type'] ?? null;

        // ── Driver broadcasts location ─────────────────────────────────────────
        // Expected payload:
        //   { "type": "ABANG_LOCATION", "sessionId": "<work_session.session_id>",
        //     "lat": -6.xxx, "lng": 106.xxx }
        if ($type === 'ABANG_LOCATION') {
            $sessionId = $data['sessionId'] ?? $data['abangId'] ?? null;
            if (!$sessionId) return;

            $this->abangOnline[$sessionId] = [
                'lat'       => (float)$data['lat'],
                'lng'       => (float)$data['lng'],
                'updatedAt' => time(),
            ];
        }

        // ── Customer asks for nearby drivers ──────────────────────────────────
        // Expected payload:
        //   { "type": "GET_NEARBY_ABANG", "lat": -6.xxx, "lng": 106.xxx,
        //     "radius": 3000 }   ← radius in metres, default 3 km
        if ($type === 'GET_NEARBY_ABANG') {
            $lat    = (float)($data['lat']    ?? 0);
            $lng    = (float)($data['lng']    ?? 0);
            $radius = (float)($data['radius'] ?? 3000);

            $result = [];

            foreach ($this->abangOnline as $sessionId => $pos) {
                // Auto-offline after 60 s without a ping
                if (time() - $pos['updatedAt'] > 60) {
                    unset($this->abangOnline[$sessionId], $this->stockCache[$sessionId]);
                    continue;
                }

                $dist = $this->haversine($lat, $lng, $pos['lat'], $pos['lng']);
                if ($dist > $radius) continue;

                $db = $this->fetchStockCached($sessionId);

                $result[] = [
                    'abangId'     => $sessionId,
                    'lat'         => $pos['lat'],
                    'lng'         => $pos['lng'],
                    'distance'    => (int)round($dist),  // metres
                    'name'        => $db['name']   ?? 'Abang',
                    'stocks'      => $db['stocks'] ?? [],
                ];
            }

            usort($result, fn($a, $b) => $a['distance'] - $b['distance']);

            $from->send(json_encode([
                'type' => 'NEARBY_ABANG',
                'data' => $result,
            ]));
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        echo "Disconnected: {$conn->resourceId}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function fetchStockCached(string $sessionId): array
    {
        $now    = time();
        $cached = $this->stockCache[$sessionId] ?? null;

        if ($cached && ($now - $cached['cachedAt']) < $this->stockCacheTtl) {
            return $cached['data'];
        }

        $fresh = $this->fetchFromDb($sessionId);
        $this->stockCache[$sessionId] = ['data' => $fresh, 'cachedAt' => $now];
        return $fresh;
    }

    private function fetchFromDb(string $sessionId): array
    {
        $schema = $this->schema;
        $sid    = mysqli_real_escape_string($this->db, $sessionId);

        // Reconnect if the long-lived connection dropped
        if (!mysqli_ping($this->db)) {
            mysqli_close($this->db);
            $this->db = new \mysqli(
                $_ENV['DB_HOST'] ?? '127.0.0.1',
                $_ENV['DB_USER'] ?? '',
                $_ENV['DB_PASS'] ?? '',
                'movira_core_dev',
                (int)($_ENV['DB_PORT'] ?? 3306)
            );
            $this->db->set_charset('utf8mb4');
            $this->db->query("SET time_zone = '+07:00'");
        }

        // Driver name
        $rSession = mysqli_query($this->db,
            "SELECT ws.user_id
             FROM {$schema}.work_session ws
             WHERE ws.session_id = '$sid' AND ws.status = 'active'
             LIMIT 1"
        );

        if (!$rSession || mysqli_num_rows($rSession) === 0) {
            return ['name' => 'Abang', 'stocks' => []];
        }

        $row  = mysqli_fetch_assoc($rSession);
        $name = $this->maskDriver($row['user_id']);

        // Stocks: qty_start minus qty already sold this session
        $rStocks = mysqli_query($this->db,
            "SELECT m.menu_id, m.menu_name, m.thumb_url, m.price,
                    wss.qty_start,
                    COALESCE(SUM(td.quantity), 0) AS qty_sold
             FROM {$schema}.work_session_stock wss
             JOIN  {$schema}.menu m
                ON m.menu_id = wss.menu_id
             LEFT JOIN {$schema}.transaction t
                ON t.session_id = wss.session_id
             LEFT JOIN {$schema}.transaction_detail td
                ON td.transaction_id = t.transaction_id
               AND td.menu_id        = m.menu_id
             WHERE wss.session_id = '$sid'
             GROUP BY m.menu_id, m.menu_name, m.thumb_url, m.price, wss.qty_start
             ORDER BY m.menu_name ASC"
        );

        $stocks = [];
        if ($rStocks) {
            while ($s = mysqli_fetch_assoc($rStocks)) {
                $qtyLeft  = max(0, (int)$s['qty_start'] - (int)$s['qty_sold']);
                $stocks[] = [
                    'menu_id'   => $s['menu_id'],
                    'menu_name' => $s['menu_name'],
                    'thumb_url' => $s['thumb_url'],
                    'price'     => (int)$s['price'],
                    'qty_left'  => $qtyLeft,
                ];
            }
        }

        return ['name' => $name, 'stocks' => $stocks];
    }

    private function maskDriver(string $raw): string
    {
        $u = preg_replace('/\s+/', '', $raw);
        if (strlen($u) <= 6) return 'Abang ' . $u;
        return 'Abang ' . substr($u, 0, 4) . '****' . substr($u, -3);
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R    = 6371000; // metres
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a    = sin($dLat / 2) ** 2
              + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 2 * $R * asin(sqrt($a));
    }
}
