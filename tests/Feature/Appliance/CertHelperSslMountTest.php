<?php

/**
 * The CertificateManager runs sysadmin/cert-helper INSIDE the app container (PHP
 * Process), and the helper inspects /etc/nginx/ssl/sos-vault.com/fullchain.pem.
 * The app container therefore must mount the same host ssl dir the nginx service
 * uses, or `inspect` reports "missing" even though the installer created a cert.
 *
 * Guards docker-compose.appliance.yml — the compose stack shipped in the deb.
 */

use Symfony\Component\Yaml\Yaml;

it('mounts the nginx ssl dir into the app container so cert-helper can inspect the cert', function () {
    $compose = Yaml::parseFile(base_path('docker-compose.appliance.yml'));

    $appVolumes = $compose['services']['app']['volumes'] ?? [];
    $nginxVolumes = $compose['services']['nginx']['volumes'] ?? [];

    // :z — SELinux shared relabel so container_t can read the cert on RHEL/
    // AlmaLinux hosts; a silent no-op on AppArmor (Ubuntu) hosts.
    $sslMount = '/opt/sos-vault/docker-compose/nginx/ssl:/etc/nginx/ssl:z';

    // Both containers see the same host ssl dir at the path cert-helper defaults to.
    expect($appVolumes)->toContain($sslMount)
        ->and($nginxVolumes)->toContain($sslMount);
});

it('mounts a host-persisted corp CA store into the app container trust path', function () {
    $compose = Yaml::parseFile(base_path('docker-compose.appliance.yml'));

    $appVolumes = $compose['services']['app']['volumes'] ?? [];

    // Uploaded corp CAs persist on the host and land where update-ca-certificates
    // reads them; container_start.sh folds them into the trust bundle on boot.
    expect($appVolumes)->toContain('/opt/sos-vault/docker-compose/ca-certificates:/usr/local/share/ca-certificates:z');
});

it('mounts the host machine-id read-only so MachineTokenService binds to the host', function () {
    $compose = Yaml::parseFile(base_path('docker-compose.appliance.yml'));

    $appVolumes = $compose['services']['app']['volumes'] ?? [];

    // MachineTokenService's live fallback (when no encrypted fingerprint is
    // stored) reads /etc/machine-id. Inside the container that must be the
    // HOST's machine-id — the container's own is absent/empty on some base
    // images (e.g. Ubuntu 26.04) — so the fallback stays host-bound.
    expect($appVolumes)->toContain('/etc/machine-id:/etc/machine-id:ro');
});

it('mounts the host AI model dir into the app container so downloads land where llama reads them', function () {
    $compose = Yaml::parseFile(base_path('docker-compose.appliance.yml'));

    $appVolumes = $compose['services']['app']['volumes'] ?? [];
    $llamaVolumes = $compose['services']['llama']['volumes'] ?? [];

    // The app writes the GGUF to model_dir = base_path('models') = /var/www/site/models
    // (rw); the llama service loads it from the SAME host dir (/opt/sos-vault/models,
    // read-only). Without the app-side mount, DownloadAiModelJob's mkdir fails with
    // "Could not create model directory" and llama never sees the weights.
    expect($appVolumes)->toContain('/opt/sos-vault/models:/var/www/site/models:z')
        ->and($llamaVolumes)->toContain('/opt/sos-vault/models:/models:ro,z');
});
