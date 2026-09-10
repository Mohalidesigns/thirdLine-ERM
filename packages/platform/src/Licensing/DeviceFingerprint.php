<?php

namespace ThirdLine\Platform\Licensing;

class DeviceFingerprint
{
    /**
     * Memoized per-instance (the service is container-bound as a singleton, so
     * per-request in practice) — generate() shells out to OS tooling, and
     * callers (validate, heartbeat, activate) may each ask several times.
     */
    private ?string $memoized = null;

    public function generate(): string
    {
        if ($this->memoized !== null) {
            return $this->memoized;
        }

        // Per-OS component sets. Every input MUST be stable across reboots and
        // network changes, or the licence's device binding (`dvc` claim) breaks
        // and the app locks until the user re-activates.
        //
        // Darwin: gethostname() and the en0 MAC are NOT stable on macOS — the
        // .local hostname gets a "-2" suffix after mDNS conflicts, and modern
        // macOS rotates private Wi-Fi MAC addresses. That drift forced a manual
        // re-activation after nearly every reboot. Use the IOPlatformUUID and
        // hardware serial instead: both survive reboots, renames, and network
        // hops, and only change with a logic-board swap.
        //
        // Linux (production): the original component set is stable on servers
        // and MUST NOT change — altering it would re-fingerprint every deployed
        // VPS and invalidate its existing activation.
        $components = PHP_OS_FAMILY === 'Darwin'
            ? [
                'cpu' => $this->getCpuId(),
                'platform_uuid' => $this->getPlatformUuid(),
                'serial' => $this->getDiskSerial(),
            ]
            : [
                'cpu' => $this->getCpuId(),
                'disk' => $this->getDiskSerial(),
                'hostname' => gethostname(),
                'mac' => $this->getMacAddress(),
            ];

        ksort($components);
        $raw = implode('|', array_map(
            fn ($k, $v) => "$k=$v",
            array_keys($components),
            array_values($components)
        ));

        return $this->memoized = 'sha256:'.hash('sha256', $raw);
    }

    public function generateFingerprintFile(): string
    {
        // Report the components that actually feed the hash for THIS OS, so an
        // admin inspecting the file for offline binding sees the real inputs.
        $components = PHP_OS_FAMILY === 'Darwin'
            ? [
                'cpu' => $this->getCpuId(),
                'platform_uuid' => $this->getPlatformUuid(),
                'serial' => $this->getDiskSerial(),
            ]
            : [
                'cpu' => $this->getCpuId(),
                'disk' => $this->getDiskSerial(),
                'mac' => $this->getMacAddress(),
            ];

        $data = [
            'fingerprint' => $this->generate(),
            'hostname' => gethostname(),
            'os' => PHP_OS_FAMILY.' '.php_uname('r'),
            'php_version' => PHP_VERSION,
            'generated_at' => now()->toIso8601String(),
            'components' => $components,
        ];

        return base64_encode(json_encode($data));
    }

    public function matches(string $storedFingerprint): bool
    {
        return hash_equals($storedFingerprint, $this->generate());
    }

    private function getCpuId(): string
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $cpuInfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuInfo && preg_match('/model name\s*:\s*(.+)/i', $cpuInfo, $m)) {
                return trim($m[1]);
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $cpu = @shell_exec('sysctl -n machdep.cpu.brand_string 2>/dev/null');
            if ($cpu) {
                return trim($cpu);
            }
        }

        return php_uname('m').'-'.php_uname('p');
    }

    /**
     * macOS only: the IOPlatformUUID is a per-machine identifier that is stable
     * across reboots, hostname changes, and network moves.
     */
    private function getPlatformUuid(): string
    {
        $out = @shell_exec("ioreg -rd1 -c IOPlatformExpertDevice 2>/dev/null | awk -F'\"' '/IOPlatformUUID/{print \$4}'");
        if ($out && trim($out) !== '') {
            return trim($out);
        }

        return 'unknown-platform-uuid';
    }

    private function getDiskSerial(): string
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $serial = @shell_exec('lsblk -ndo SERIAL /dev/sda 2>/dev/null');
            if ($serial) {
                return trim($serial);
            }
            $uuid = @shell_exec('findmnt -n -o UUID / 2>/dev/null');
            if ($uuid) {
                return trim($uuid);
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $serial = @shell_exec('system_profiler SPHardwareDataType 2>/dev/null | grep "Serial Number" | awk \'{print $NF}\'');
            if ($serial) {
                return trim($serial);
            }
        }

        return 'unknown-disk';
    }

    private function getMacAddress(): string
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $mac = @shell_exec("ip link show | grep -m1 'link/ether' | awk '{print \$2}' 2>/dev/null");
            if ($mac) {
                return trim($mac);
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $mac = @shell_exec("ifconfig en0 2>/dev/null | grep ether | awk '{print \$2}'");
            if ($mac) {
                return trim($mac);
            }
        }

        return 'unknown-mac';
    }
}
