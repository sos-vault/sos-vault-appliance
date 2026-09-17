<?php

/**
 * Open-core licensing strings (Deutsch).
 */
return [

    'status' => [
        'active' => 'AKTIV',
        'expired' => 'ABGELAUFEN',
        'revoked' => 'WIDERRUFEN',
        'none' => 'KEINE',
    ],

    'banner' => [
        'unlicensed_summary' => 'Läuft im Open-Core-Grundmodus: ein Administrator, unverschlüsseltes Vault-Verzeichnis. Installieren Sie eine Lizenz, um Mehrbenutzerbetrieb, Gruppen, Module, ITSM-Integration, verschlüsselte Vaults und das Ereignisprotokoll freizuschalten.',
        'expired_summary' => 'Lizenz abgelaufen. Lizenzpflichtige Funktionen sind vorübergehend deaktiviert. Erneuern Sie die Lizenz, um den Zugriff wiederherzustellen.',
        'cta_install' => 'Lizenz installieren',
    ],

    'request' => [
        'section_heading' => 'Lizenz anfordern',
        'section_description' => 'Erzeugt einen kurzen Schlüssel, der an die Hardware dieses Servers gebunden ist. Kopieren Sie ihn und fügen Sie ihn auf sos-vault.com (Lizenzanforderung verifizieren) ein, um eine Lizenz zu erwerben, die genau zu diesem Server passt.',
        'button_generate' => 'Lizenzanforderung erzeugen',
        'button_copy' => 'Schlüssel kopieren',
        'button_copied' => 'Kopiert',
        'key_heading' => 'Ihr Lizenzanforderungs-Schlüssel',
        'key_helper' => 'Kopieren Sie diesen Schlüssel und fügen Sie ihn auf sos-vault.com unter „Lizenzanforderung verifizieren" ein. Er kann gefahrlos weitergegeben werden — er enthält nur den Hardware-Fingerabdruck dieses Servers.',
        'notif_key_ready' => 'Lizenzanforderungs-Schlüssel bereit',
        'notif_key_ready_body' => 'Kopieren Sie den Schlüssel unten und fügen Sie ihn auf sos-vault.com unter „Lizenzanforderung verifizieren" ein.',
        'notif_failed' => 'Lizenzanforderung konnte nicht erzeugt werden',
    ],

    'expired_non_admin_blocked' => 'Diese Appliance hat derzeit keine aktive Lizenz. Nur der Administrator kann sich anmelden. Bitten Sie Ihren Betreiber, die Lizenz zu erneuern oder zu installieren.',

    'user_creating_single_admin' => 'Im Open-Core-Grundmodus ist nur ein Administrator zulässig. Installieren Sie eine Lizenz, um weitere Benutzer hinzuzufügen.',

    'modules_unavailable' => 'Die Modulinstallation erfordert eine aktive Lizenz.',
    'event_log_unavailable' => 'Das Ereignisprotokoll ist nur auf einer lizenzierten Appliance verfügbar.',

    'disk_manager' => [
        'unlicensed_title' => 'Vault-Verzeichnis',
        'vault_dir_label' => 'Pfad zum Vault-Verzeichnis',
        'vault_dir_helper' => 'Der Open-Core-Grundmodus verwendet ein unverschlüsseltes Verzeichnis als Vault. Standard: /vault. Muss ein absoluter Pfad auf dem Host sein.',
        'save_button' => 'Speichern',
        'save_notif' => 'Vault-Verzeichnis gespeichert.',
    ],

    'dashboard' => [
        'unlicensed_title' => 'Lizenz',
        'unlicensed_value' => 'OPEN-CORE',
        'unlicensed_callout' => 'Installieren Sie eine Lizenz, um Mehrbenutzerbetrieb, Gruppen, Module, ITSM, verschlüsselte Vaults und das Ereignisprotokoll freizuschalten.',
    ],

    'event' => [
        'request_generated' => 'Lizenzanforderung erzeugt',
        'license_installed' => 'Lizenz installiert',
        'license_expired' => 'Lizenz abgelaufen',
        'login_blocked' => 'Anmeldung eines Nicht-Administrators blockiert (keine Lizenz)',
    ],

];
