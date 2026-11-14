<?php
declare(strict_types=1);

/*
 * Nova Ops Status — configuration.
 *
 * No host passwords live here. Every remote host is reached with the
 * dedicated ed25519 probe key (public key already authorised on all four
 * targets). This file is outside the nginx document root and is additionally
 * denied at the vhost layer.
 */

return [
    'app_name'    => 'Nova Ops Status',
    'base_dir'    => '/var/www/status.novaspack.com',
    'data_dir'    => '/var/www/status.novaspack.com/data',
    'scripts'     => '/var/www/status.novaspack.com/app/scripts',
    'ssh_bin'     => '/usr/bin/ssh',
    'ssh_key'     => '/etc/status.novaspack/status_probe_ed25519',
    'known_hosts' => '/etc/status.novaspack/known_hosts',
    'timezone'    => 'UTC',

    // Hard ceilings (seconds) for each entry point.
    'budget' => [
        'web'        => 7,
        'cli'       => 150,
        'all'        => 12,
        'one'        => 10,
        'ssh'        => 12, // wall clock for a single remote probe
        'local'      => 8,
    ],

    'http' => [
        'connect_timeout' => 4,
        'timeout'         => 6,
        'concurrency' => 4,
        'log_lines'       => 15,
        // The only path we ever request is '/'. /login and /index.php are
        // allowlisted by the guard but deliberately NOT probed: nothing extra
        // is learned from them, and every request we do not send is a request
        // that cannot trigger anything.
        'allowlist'       => ['/', '/login', '/index.php'],
        'probe_extra'     => false,
    ],

    'actions' => [
        'rate_limit_seconds' => 5,
    ],

    'history' => [
        'max_events' => 60,
        'show_events' => 50,
        'flap_window' => 900,
        'flap_count'  => 3,
    ],

    'targets' => [
        'vps-a' => [
            'label'    => 'VPS-A',
            'note'     => 'this host (local, no ssh hop)',
            'type'     => 'vps',
            'access'   => 'local',
            'addr'     => '161.97.166.44',
            'interval' => 120,
            'fresh' => 150,
        ],
        'vps-b' => [
            'label'    => 'VPS-B',
            'note'     => 'root@62.171.140.132',
            'type'     => 'vps',
            'access'   => 'ssh',
            'addr'     => '62.171.140.132',
            'port'     => 22,
            'user'     => 'root',
            'interval' => 300,
            'fresh' => 400,
        ],
        'cpanel-a' => [
            'label'    => 'cPanel-A',
            'note'     => 'shared host, no root',
            'type'     => 'cpanel',
            'access'   => 'ssh',
            'addr'     => '184.94.213.176',
            'port'     => 21098,
            'user'     => 'tipmrnhl',
            'interval' => 1800,
            'fresh' => 2100,
        ],
        'cpanel-b' => [
            'label'    => 'cPanel-B',
            'note'     => 'shared host, no root',
            'type'     => 'cpanel',
            'access'   => 'ssh',
            'addr'     => '66.29.137.179',
            'port'     => 21098,
            'user'     => 'gbsbklvy',
            'interval' => 1800,
            'fresh' => 2100,
        ],
    ],
];
