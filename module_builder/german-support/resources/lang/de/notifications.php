<?php

return [
    'title' => 'Benachrichtigungen',
    'no_notifications' => 'Keine Benachrichtigungen.',
    'mark_all_read' => 'Alle als gelesen markieren',

    // Notifications page
    'page_heading' => 'Benachrichtigungen',
    'page_description' => 'Ihre aktuellen Benachrichtigungen.',
    'page_empty_heading' => 'Keine Benachrichtigung gefunden',
    'page_empty_description' => 'Alle Benachrichtigungen werden hier angezeigt.',
    'page_mark_as_read' => 'Als gelesen markieren',
    'page_from' => 'Von: :name',

    // Announcements page
    'ann_heading' => 'Ankündigungen',
    'ann_description' => 'Allgemeine Systemkommunikation.',
    'ann_empty_heading' => 'Keine Ankündigungen gefunden',
    'ann_empty_description' => 'Alle allgemeinen Ankündigungen werden hier angezeigt.',
    'ann_view_all' => 'Alle Ankündigungen anzeigen',
    'ann_written_on' => 'Geschrieben am :date',

    // Vault lifecycle
    'vault_no_associated' => 'Kein Vault für :username zugeordnet.',
    'vault_not_found' => 'Kein Vault für :username gefunden.',
    'vault_not_open' => 'Vault ist für :username nicht geöffnet.',
    'vault_expand_failed' => 'Vault-Erweiterung auf :size fehlgeschlagen.',
    'vault_expand_success' => 'Vault erfolgreich auf :size erweitert.',
    'vault_shrink_failed' => 'Vault-Verkleinerung auf :size fehlgeschlagen.',
    'vault_shrink_success' => 'Vault erfolgreich auf :size verkleinert.',
    'vault_adjust_failed' => 'Vault-Anpassung fehlgeschlagen.',
    'vault_adjust_success' => 'Vault-Anpassung war erfolgreich.',
    'vault_busy' => 'Ihr Vault ist derzeit beschäftigt. Bitte versuchen Sie es in ein paar Minuten erneut.',
    'vault_busy_stale' => 'Bitte versuchen Sie es erneut.',
    'vault_reopen_failed' => ' Vault konnte nicht erneut geöffnet werden.',
    'vault_size_changed' => ' Vault-Größe von :old auf :new geändert.',
    'vault_tokens_adjusted' => ' Token-Guthaben von :old auf :new angepasst.',
    'vault_resize_unexpected' => 'Vault-Größenänderung unerwartet fehlgeschlagen. Bitte kontaktieren Sie den Support.',

    // Admin vault actions
    'admin_vault_expand_success' => 'Ihr Vault wurde vom Systemadministrator erfolgreich um :size MB erweitert.',
    'admin_vault_expand_failed' => 'Ihr Vault konnte vom Systemadministrator nicht um :size MB erweitert werden.',
    'admin_vault_shrink_success' => 'Ihr Vault wurde vom Systemadministrator erfolgreich um :size MB verkleinert.',
    'admin_vault_shrink_failed' => 'Ihr Vault konnte vom Systemadministrator nicht um :size MB verkleinert werden.',

    // Scheduler
    'scheduler_shrink_no_space' => 'Geplante Vault-Verkleinerung um :size konnte nicht ausgeführt werden: Freier Speicher (:free) liegt unter dem erforderlichen Minimum (:required). Bitte geben Sie Speicherplatz frei, und die Verkleinerung wird automatisch erneut versucht.',

    // Upload & unpack
    'decrypt_complete' => 'Entschlüsselung abgeschlossen. Dateiname nach der Entschlüsselung: :filename',
    'extract_complete' => 'Extraktion abgeschlossen. Originaldatei wird entfernt: :filename',
    'case_create_failed' => 'Der zugehörige Fall konnte nicht erstellt werden.',
    'case_created' => 'Zugehöriger Fall :case Seriennummer :serial erfolgreich erstellt.',

    // Unpack errors
    'unpack_file_not_found' => 'Datei nicht gefunden. Kann nicht fortfahren.',
    'unpack_decrypt_failed' => 'Entschlüsselung fehlgeschlagen. Bitte überprüfen Sie die Passphrase.',
    'unpack_decrypt_pass_error' => 'Entschlüsselungspassphrase konnte nicht entschlüsselt werden.',
    'unpack_decrypt_pass_empty' => 'Entschlüsselungspassphrase ist leer.',
    'unpack_file_decrypt_failed' => 'Datei konnte nicht entschlüsselt werden.',
    'unpack_size_calc_failed' => 'Erwartete Extraktionsgröße konnte nicht berechnet werden.',
    'unpack_count_calc_failed' => 'Erwartete Dateianzahl konnte nicht berechnet werden.',
    'unpack_no_space' => 'Nicht genügend Speicherplatz im Vault zum Extrahieren der Datei.',
    'unpack_dir_unknown' => 'Extraktionszielverzeichnis konnte nicht bestimmt werden.',
    'unpack_extract_failed' => 'Extraktionsbefehl fehlgeschlagen.',
    'unpack_extract_unknown_dir' => 'Extraktion in unerwartetem Verzeichnis gelandet.',
    'unpack_dir_exists' => 'Zielverzeichnis existiert bereits. Extraktion wird abgebrochen.',
    'unpack_dir_not_found' => 'Verzeichnis nach Fallerstellung nicht gefunden.',
    'unpack_dir_not_readable' => 'Verzeichnis nach Fallerstellung nicht lesbar.',
    'unpack_extraction_complete' => 'Dateiextraktion abgeschlossen.',
    'unpack_kept_in_vault' => ':reason Datei wurde im Vault behalten.',
    'unpack_decrypt_no_key' => 'Datei-Entschlüsselung fehlgeschlagen. Kann nicht fortfahren.',

    // API upload & unpack
    'upload_auto_failed' => 'Der automatische Upload ist fehlgeschlagen, weil: :reason',
    'upload_file_success' => 'Datei-Upload erfolgreich: :filename',
    'upload_auto_success' => ':message :filename',
    'unpack_auto_failed' => 'Das automatische Entpacken ist fehlgeschlagen, weil: :reason',
    'upload_success_unpack_failed' => 'Datei erfolgreich hochgeladen, aber das automatische Entpacken ist fehlgeschlagen, weil: :reason',
    'file_upload_unpack_failed' => 'Datei :filename wurde erfolgreich hochgeladen, aber das automatische Entpacken ist fehlgeschlagen: :reason. Datei ist im Vault verfügbar.',
    'upload_auto_complete' => 'Automatischer Datei-Upload erfolgreich. :details',

    // JIRA integration
    'jira_download_failed' => 'Download von Anhang :filename für Issue :issueid fehlgeschlagen. :error',
    'jira_download_success' => 'Download von Anhang :filename für Issue :issueid erfolgreich.',

    // Billing & subscription
    'subscription_product_error' => 'Fehler beim Auffinden der Abonnement-Produkt-ID. Bitte kontaktieren Sie uns, wenn Sie glauben, dass dies falsch ist.',
    'transaction_error' => 'Fehler bei der Transaktionsverarbeitung. Bitte versuchen Sie es erneut.',
    'wrong_plan_disk_expand' => 'Falscher Plantyp für Festplattenerweiterung.',
    'wrong_plan_disk_cancel' => 'Falscher Plantyp für Festplattenabbestellung.',
    'wrong_plan_disk_schedule' => 'Falscher Plantyp für geplante Festplattenabbestellung.',
    'wrong_plan_tokens' => 'Falscher Plantyp für Token-Kauf.',
    'vault_expand_requested' => 'Vault-Erweiterung um :size angefordert. Diese Operation kann mehrere Minuten dauern.',
    'vault_shrink_requested' => 'Vault-Verkleinerung um :size angefordert. Diese Operation kann mehrere Minuten dauern.',
    'vault_shrink_scheduled' => 'Vault-Verkleinerung um :size für :date geplant.',
    'vault_shrink_no_disk' => 'Vault-Verkleinerungsanforderung fehlgeschlagen. Keine aktive Festplattenerweiterung gefunden.',
    'tokens_purchase_success' => 'Der Token-Kauf war erfolgreich. Ihr neues Token-Guthaben beträgt :tokens Token.',

    // Paddle webhooks
    'subscription_payment_received' => 'Ihre Abonnementzahlung wurde empfangen. Neues Token-Guthaben: :tokens.',
    'subscription_cancelled' => 'Ihr Abonnement wurde gekündigt.',
    'disk_expansion_cancelled' => 'Ihr Festplattenerweiterungs-Abonnement wurde gekündigt.',

    // Plan switching
    'plan_upgraded' => 'Ihr Plan wurde auf :plan aufgewertet. Ihr Vault und Ihre Token werden angepasst.',
    'plan_downgraded_scheduled' => 'Ihr Plan wurde auf :plan umgestellt. Ihr Vault wird am :date reduziert.',
    'plan_downgrade_blocked' => 'Ihr Plan kann nicht herabgestuft werden, solange Sie aktive Festplattenerweiterungen haben. Bitte kündigen Sie diese zuerst.',
    'plan_switch_error' => 'Beim Wechsel Ihres Plans ist ein Fehler aufgetreten. Bitte versuchen Sie es erneut.',

    // Extra seat add-on
    'wrong_plan_seats' => 'Falscher Plantyp für den Sitzplatzkauf.',
    'seat_addon_requires_team' => 'Zusätzliche Plätze sind nur für Team-, Enterprise- und Self-hosted-Pläne verfügbar.',
    'seat_addon_no_group' => 'Für Ihr Konto wurde keine Teamgruppe gefunden.',
    'seat_purchase_success' => ':qty zusätzlicher Platz/Plätze hinzugefügt. Ihr Team kann jetzt bis zu :total Mitglieder haben.',
    'seat_cancelled' => ':qty Platz/Plätze entfernt. :suspended Konto(s) wurden gesperrt.',
];
