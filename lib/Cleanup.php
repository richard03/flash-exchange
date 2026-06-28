<?php
class Cleanup {
    public static function run(): void {
        $tmp    = rtrim(sys_get_temp_dir(), '/');
        $cutoff = time() - 900;

        foreach (glob($tmp . '/fx_*.json') ?: [] as $json) {
            if (@filemtime($json) > $cutoff) continue;
            $data = json_decode(@file_get_contents($json), true);
            if (is_array($data) && !empty($data['pending']['transfer_id'])) {
                $bin = $tmp . '/fx_' . $data['pending']['transfer_id'] . '.bin';
                if (file_exists($bin)) unlink($bin);
            }
            unlink($json);
        }

        foreach (glob($tmp . '/fx_*.bin') ?: [] as $bin) {
            if (@filemtime($bin) < $cutoff) unlink($bin);
        }
    }
}
