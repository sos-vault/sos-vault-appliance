<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Spanish translations for plan names and descriptions
    private array $planTranslations = [
        'Minimal' => ['es_name' => 'Mínimo',          'es_desc' => 'Acceso a un subconjunto de funciones básicas.'],
        'Basic' => ['es_name' => 'Básico',           'es_desc' => 'Acceso a todas las funciones básicas.'],
        'Team' => ['es_name' => 'Equipo',           'es_desc' => 'Hasta 8 miembros con acceso a todas las funciones.'],
        'Enterprise' => ['es_name' => 'Empresarial',      'es_desc' => 'Hasta 20 miembros en un vault compartido de gran tamaño con acceso a todas las funciones.'],
        'Free' => ['es_name' => 'Gratuito',         'es_desc' => 'Prueba gratuita de 21 días con acceso completo'],
        '10GB' => ['es_name' => '10GB',             'es_desc' => 'Ampliación de 10 GB de vault'],
        '30GB' => ['es_name' => '30GB',             'es_desc' => 'Ampliación de 30 GB de vault'],
        '50GB' => ['es_name' => '50GB',             'es_desc' => 'Ampliación de 50 GB de vault'],
        '100GB' => ['es_name' => '100GB',            'es_desc' => 'Ampliación de 100 GB de vault'],
        '4MToken' => ['es_name' => '4M de tokens',     'es_desc' => '4 millones de tokens'],
        '14MToken' => ['es_name' => '14M de tokens',    'es_desc' => '14 millones de tokens'],
        '30MToken' => ['es_name' => '30M de tokens',    'es_desc' => '30 millones de tokens'],
        '70MToken' => ['es_name' => '70M de tokens',    'es_desc' => '70 millones de tokens'],
    ];

    // Spanish translations for feature names (canonical English → Spanish)
    private array $featureNameTranslations = [
        'API access' => 'Acceso API',
        'Advanced Tools' => 'Herramientas avanzadas',
        'Assistant' => 'Asistente',
        'Basic Tools' => 'Herramientas básicas',
        'Direct Upload' => 'Carga directa',
        'ITSM Integration' => 'Integración ITSM',
        'Included Tokens' => 'Tokens incluidos',
        'Special Tools' => 'Herramientas especiales',
        'Support' => 'Soporte',
        'Team Size' => 'Tamaño del equipo',
        'Token Store Access' => 'Acceso a la tienda de tokens',
        'User Admin' => 'Administración de usuarios',
        'Vault Increase Access' => 'Acceso a ampliación de vault',
        'Vault Size' => 'Tamaño del vault',
    ];

    // Spanish translations for feature descriptions (exact English → Spanish)
    private array $featureDescTranslations = [
        'sos report command direct upload access.' => 'Acceso de carga directa mediante el comando de informe sos.',
        'External ticket system integration access.' => 'Acceso a la integración con sistemas externos de tickets.',
        'Assistant tokens / month.' => 'Tokens del asistente / mes.',
        'Assistant tokens per account is 8 M / month.' => 'Los tokens del asistente por cuenta son 8 M / mes.',
        'Shared assistant tokens / month.' => 'Tokens del asistente compartidos / mes.',
        'Human email support' => 'Soporte humano por correo electrónico',
        'Single person account.' => 'Cuenta de una sola persona.',
        'Team of individual accounts with individual vaults.' => 'Equipo de cuentas individuales con vaults independientes.',
        'Team sharing the same vault.' => 'Equipo que comparte el mismo vault.',
        'Role based user administration panel.' => 'Panel de administración de usuarios basado en roles.',
        'Storage capacity per vault is 10 GB.' => 'La capacidad de almacenamiento por vault es de 10 GB.',
        'Shared storage capacity.' => 'Capacidad de almacenamiento compartida.',
    ];

    public function up(): void
    {
        // Add Spanish translations to plans
        foreach ($this->planTranslations as $enName => $translations) {
            DB::update(
                "UPDATE plans
                 SET name        = json_set(name,        '$.es', ?),
                     description = json_set(description, '$.es', ?)
                 WHERE json_extract(name, '$.en') = ?",
                [$translations['es_name'], $translations['es_desc'], $enName]
            );
        }

        // Add Spanish translations to feature names
        foreach ($this->featureNameTranslations as $enName => $esName) {
            DB::update(
                "UPDATE plan_features
                 SET name = json_set(name, '$.es', ?)
                 WHERE json_extract(name, '$.en') = ?",
                [$esName, $enName]
            );
        }

        // Add Spanish translations to feature descriptions (only non-empty ones)
        foreach ($this->featureDescTranslations as $enDesc => $esDesc) {
            DB::update(
                "UPDATE plan_features
                 SET description = json_set(description, '$.es', ?)
                 WHERE json_extract(description, '$.en') = ?",
                [$esDesc, $enDesc]
            );
        }
    }

    public function down(): void
    {
        // Remove Spanish keys from plans
        DB::update("UPDATE plans SET name        = json_remove(name,        '$.es')");
        DB::update("UPDATE plans SET description = json_remove(description, '$.es')");

        // Remove Spanish keys from plan_features
        DB::update("UPDATE plan_features SET name        = json_remove(name,        '$.es')");
        DB::update("UPDATE plan_features SET description = json_remove(description, '$.es')");
    }
};
