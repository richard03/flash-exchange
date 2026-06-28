<?php
require_once __DIR__ . '/Session.php';

class Transfer {
    private static function binPath(string $tid): string {
        $safe = preg_replace('/[^a-f0-9]/', '', strtolower($tid));
        return rtrim(sys_get_temp_dir(), '/') . '/fx_' . $safe . '.bin';
    }

    public static function setAction(string $sid, string $action): bool {
        $valid = ['pc_to_mobile_text', 'pc_to_mobile_file', 'mobile_to_pc_text', 'mobile_to_pc_file'];
        if (!in_array($action, $valid, true)) return false;
        return Session::update($sid, function (array $data) use ($action): array {
            $data['action'] = $action;
            return $data;
        });
    }

    public static function reset(string $sid): void {
        Session::update($sid, function (array $data): array {
            if (!empty($data['pending']['transfer_id'])) {
                $bin = rtrim(sys_get_temp_dir(), '/') . '/fx_' . $data['pending']['transfer_id'] . '.bin';
                if (file_exists($bin)) unlink($bin);
            }
            $data['action']  = null;
            $data['pending'] = null;
            return $data;
        });
    }

    public static function storeText(string $sid, string $from, string $text): ?string {
        $tid      = bin2hex(random_bytes(8));
        $for_role = $from === 'pc' ? 'mobile' : 'pc';
        $ok = Session::update($sid, function (array $data) use ($tid, $from, $text, $for_role): array {
            $data['pending'] = [
                'transfer_id' => $tid,
                'from'        => $from,
                'type'        => 'text',
                'content'     => $text,
                'for_role'    => $for_role,
                'created_at'  => time(),
            ];
            return $data;
        });
        return $ok ? $tid : null;
    }

    public static function storeFile(string $sid, string $from, array $file): ?string {
        $tid      = bin2hex(random_bytes(8));
        $for_role = $from === 'pc' ? 'mobile' : 'pc';
        $bin      = self::binPath($tid);
        if (!move_uploaded_file($file['tmp_name'], $bin)) return null;
        $ok = Session::update($sid, function (array $data) use ($tid, $from, $file, $for_role): array {
            $data['pending'] = [
                'transfer_id' => $tid,
                'from'        => $from,
                'type'        => 'file',
                'filename'    => $file['name'],
                'filesize'    => $file['size'],
                'for_role'    => $for_role,
                'created_at'  => time(),
            ];
            return $data;
        });
        if (!$ok) { unlink($bin); return null; }
        return $tid;
    }

    public static function consumePending(string $sid, string $role): ?array {
        $result = null;
        Session::update($sid, function (array $data) use ($role, &$result): array {
            if (empty($data['pending']) || $data['pending']['for_role'] !== $role) return $data;
            $result = $data['pending'];
            if ($data['pending']['type'] === 'text') {
                $data['pending'] = null;
            }
            return $data;
        });
        return $result;
    }

    public static function deliverFile(string $sid, string $tid): ?string {
        $safe = preg_replace('/[^a-f0-9]/', '', strtolower($tid));
        $bin  = rtrim(sys_get_temp_dir(), '/') . '/fx_' . $safe . '.bin';
        if (!file_exists($bin)) return null;
        Session::update($sid, function (array $data) use ($tid): array {
            if (isset($data['pending']['transfer_id']) && $data['pending']['transfer_id'] === $tid) {
                $data['pending'] = null;
            }
            return $data;
        });
        return $bin;
    }
}
