# Known-good installation baseline: 1.6.0

The operator supplied `studioIntegration-ojs-3.5-v1.6.0.tar.gz` as a package that worked on their OJS installation. Its SHA-256 is:

```text
db752d6ef57d5670cb13e4e75ce3450c3521da04474dc695bac36d1fb1d6b353
```

The publicly published v1.6.0 asset has exactly the same bytes. CI can therefore retrieve this pinned baseline without repacking or modifying the operator's file.

## Observed differences

| Item | Working 1.6.0 package | 1.8.7 candidate |
| --- | --- | --- |
| Archive root | `studioIntegration/` | `studioIntegration/` |
| File/directory modes | 0644 / 0755 | 0644 / 0755 |
| Recorded archive UID/GID | 1001 / 1001 (`runner`) | 0 / 0 (normalized metadata) |
| `classes/Core/ApiResponse.php` | 802 bytes | Identical 802 bytes |
| `classes/Adapters/Ojs35Adapter.php` | 15,604 bytes | 15,787 bytes: adds the reconciliation-keyword accessor |
| `install.xml`, `upgrade.xml` | Absent | Native installation/upgrade integrity callbacks |
| Helper loading | Legacy `index.php` preloads selected classes | Shared loader on modern plugin/API and legacy entry paths |

In particular, an unchanged `ApiResponse.php` is among the files reported missing on the operator's newer deployment. Its disappearance cannot be attributed to a new implementation of that file. The added integrity checks detect incomplete files; their diagnostic alone does not show why a copy failed.

An earlier production log reports `Permission denied` while native OJS `FileManager::copyFile` writes selected destination files. Archive ownership metadata is an observed difference, not an established explanation for that denial. Normal native PHP extraction/copy must create installed files under the PHP account; CI explicitly checks that property for both old and new archives.

## Regression coverage

The native lifecycle matrix independently installs the unmodified 1.6.0 and 1.8.3 baselines and upgrades each to the candidate. It uses extensionless upload temporary files and a separate non-root account. Every installed file is compared with the native extraction of the original source archive; this also works for the 1.6.0 baseline, which has no integrity manifest.

Settings/permission checks, a fresh candidate install and the root/nested HTTP checks follow. This verifies the previously missing 1.6.0 upgrade path under standard OJS permissions. It does not reproduce or claim to resolve the production copy denial, nor establish support for untested OJS release families.
