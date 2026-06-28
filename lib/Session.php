<?php
class Session {
    private static function path(string $id): string {
        $safe = preg_replace('/[^a-f0-9]/', '', strtolower($id));
        return rtrim(sys_get_temp_dir(), '/') . '/fx_' . $safe . '.json';
    }

    public static function create(string $id): void {
        $data = [
            'session_id'       => $id,
            'created_at'       => time(),
            'pc_last_seen'     => time(),
            'mobile_last_seen' => 0,
            'action'           => null,
            'pending'          => null,
        ];
        file_put_contents(self::path($id), json_encode($data), LOCK_EX);
    }

    public static function load(string $id): ?array {
        $path = self::path($id);
        if (!file_exists($path)) return null;
        $fp = fopen($path, 'r');
        if (!$fp) return null;
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    public static function update(string $id, callable $fn): bool {
        $path = self::path($id);
        $fp = fopen($path, 'c+');
        if (!$fp) return false;
        flock($fp, LOCK_EX);
        $content = stream_get_contents($fp);
        $data = json_decode($content, true);
        if (!is_array($data)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }
        $data = $fn($data);
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data));
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }

    public static function delete(string $id): void {
        $data = self::load($id);
        if ($data && !empty($data['pending']['transfer_id'])) {
            $bin = rtrim(sys_get_temp_dir(), '/') . '/fx_' . $data['pending']['transfer_id'] . '.bin';
            if (file_exists($bin)) unlink($bin);
        }
        $path = self::path($id);
        if (file_exists($path)) unlink($path);
    }

    public static function touch(string $id, string $role): void {
        self::update($id, function (array $data) use ($role): array {
            $data[$role . '_last_seen'] = time();
            return $data;
        });
    }

    public static function isExpired(array $data): bool {
        $now = time();
        if ($data['mobile_last_seen'] === 0 && ($now - $data['created_at']) > 300) return true;
        $last = max($data['pc_last_seen'], $data['mobile_last_seen']);
        return ($now - $last) > 600;
    }

    public static function isConnected(array $data, string $role): bool {
        $ts = $data[$role . '_last_seen'] ?? 0;
        return $ts > 0 && (time() - $ts) <= 10;
    }
}
