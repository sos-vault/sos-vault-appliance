<?php

namespace App\Enums;

/**
 * The four knowledge areas a Mil question can fall into. The IntentRouter
 * classifies each message into one of these so only the relevant knowledge is
 * loaded into the prompt (saving tokens and focusing the small local model).
 */
enum AiIntent: string
{
    case SosVault = 'sos_vault';
    case SosCommand = 'sos_command';
    case Linux = 'linux';
    case CaseAnalysis = 'case_analysis';

    /**
     * The Sysevent `type` used for Mil usage telemetry (BOT_* family). Lets the
     * admin measure usage and response times per query kind. A message that never
     * reaches the router (a vague opener) is recorded as BOT_GENERIC by the widget.
     */
    public function botEventType(): string
    {
        return match ($this) {
            self::SosVault => 'BOT_SOS-VAULT',
            self::SosCommand => 'BOT_SOS',
            self::Linux => 'BOT_LINUX',
            self::CaseAnalysis => 'BOT_CASE',
        };
    }
}
