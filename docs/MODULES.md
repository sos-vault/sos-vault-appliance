# Modules

Modules are optional add-ons that extend the appliance with new analysis plugins, language packs, integrations, or workflow customisations. They install as `.tar.gz` (or signed `.tar.gz.gpg`) packages through the **Modules** admin page.

The Modules admin page itself requires an active license. The open-core baseline cannot install modules; that gate is enforced by `ManageModules::canAccess()` in the Filament panel.

## Authoring a module

A module is a directory tree archived as a tarball. The minimum layout:

```
my-module/
├── manifest.json
└── resources/
    └── ...
```

The `manifest.json` is the only required file. Everything else is optional and module-specific.

### manifest.json

```json
{
    "name": "my-module",
    "version": "1.0.0",
    "description": "One-sentence description of what the module does.",
    "author": "Your Name",
    "homepage": "https://example.com/my-module",
    "required_features": []
}
```

Fields:

- **name** — kebab-case identifier. Used as the install directory name. Must be unique.
- **version** — semver-style. The Modules page surfaces the version under the module's name.
- **description** — shown in the catalog UI.
- **author** — free-form.
- **homepage** — optional URL.
- **required_features** — array of feature flag strings. The license's `features` array must contain every entry here for the module to download / install. An empty array means "any active license is enough."

## Per-license access control

The Customer Portal's download endpoint (`/portal/modules/{module}/download`) enforces:

1. Caller is authenticated.
2. Module is known to the catalog (`ModuleCatalog` reads each module's `manifest.json`).
3. Caller has an active license.
4. The license's `features` array intersects the module's `required_features` (empty array passes).

Modules that ship under `module_builder/` in the source tree are the canonical version; runtime installs land under `modules/` (gitignored).

## Translations

If your module ships user-visible strings, follow the project translation rule: place language files under `resources/lang/{en,es,ja,de}/<key>.php`, mirroring the project's locale priority. Native review of non-English locales is appreciated but not required.

For German specifically, the project also keeps a copy under `module_builder/german-support/resources/lang/de/` as a fallback location. New language files in your module do not need to be mirrored there — the project lang files are mirrored, modules' are not.

## Signing modules (optional)

**Signing is optional and needs no keys for the common case.** A plain `.tar.gz` installs directly — you do **not** need any GPG key to author or install a module, so anyone who clones the repository can build and install their own modules (subject only to the active-license gate above).

Signing only matters for higher-trust distribution: sign your module and ship it as `.tar.gz.gpg`, and the appliance verifies the signature against its keyring before install. The signing key's public half must already be present in that keyring, so a self-signed package requires you to import your key on the appliance first. The Modules admin page accepts both forms.

## Testing your module

The Filament panel runs in an `id='admin'` panel; if your module adds resources, they auto-discover under `app/Filament/Resources/`. Run the project test suite (`php artisan test --compact`) plus any module-specific tests.

For the lang-file coverage rule, look at `tests/Feature/LicensingLangCoverageTest.php` as a template: assert key parity across all priority locales, no empty values, and that non-en strings actually differ from en.

## Distribution

Today the recommended distribution is "host your `.tar.gz` somewhere the operator can curl from." A module marketplace is planned but not yet shipped.
