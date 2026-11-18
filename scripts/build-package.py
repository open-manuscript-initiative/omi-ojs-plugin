#!/usr/bin/env python3
"""Build both release archives from an explicit tracked-file inventory."""
import gzip
import hashlib
import io
from pathlib import Path, PurePosixPath
import subprocess
import sys
import tarfile
import xml.etree.ElementTree as ET
import zipfile

root = Path(__file__).resolve().parent.parent
output = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else root / 'dist'
output.mkdir(parents=True, exist_ok=True)
version = ET.parse(root / 'version.xml').findtext('release')
if not version or not version.endswith('.0'):
    raise SystemExit('version.xml must contain a four-component plugin release')
package = 'studioIntegration-ojs-3.5-v' + version[:-2]
names = subprocess.check_output(['git', 'ls-files', '-z'], cwd=root).decode().split('\0')
payload = {}
for name in sorted(filter(None, names)):
    parts = PurePosixPath(name).parts
    if any(part.startswith('.') for part in parts) or parts[0] == 'dist':
        continue
    path = root / name
    if not path.is_file() or path.is_symlink():
        raise SystemExit('Missing or unsupported source file: ' + name)
    payload[name] = path.read_bytes()
for name in ['classes/Adapters/Ojs35Adapter.php', 'PackageIntegrity.php', 'bootstrap.php', 'install.xml', 'upgrade.xml']:
    if not payload.get(name):
        raise SystemExit('Missing required package file: ' + name)
payload['PACKAGE-CONTENTS.sha256'] = ''.join(
    hashlib.sha256(data).hexdigest() + '  ' + name + '\n'
    for name, data in sorted(payload.items())
).encode()
# USTAR avoids PAX extension headers; fixed metadata makes the same source
# produce the same bytes on developer machines and CI.
archive_bytes = io.BytesIO()
with tarfile.open(fileobj=archive_bytes, mode='w', format=tarfile.USTAR_FORMAT) as archive:
    directories = {'studioIntegration'}
    for name in payload:
        directories.update(str(p) for p in PurePosixPath('studioIntegration', name).parents if str(p) != '.')
    for name in sorted(directories):
        entry = tarfile.TarInfo(name + '/')
        entry.type = tarfile.DIRTYPE
        entry.mode = 0o755
        archive.addfile(entry)
    for name, data in sorted(payload.items()):
        entry = tarfile.TarInfo('studioIntegration/' + name)
        entry.size = len(data)
        entry.mode = 0o644
        archive.addfile(entry, io.BytesIO(data))
tar_path = output / (package + '.tar.gz')
with tar_path.open('wb') as target:
    with gzip.GzipFile(fileobj=target, mode='wb', filename='', mtime=0) as compressed:
        compressed.write(archive_bytes.getvalue())
zip_path = output / (package + '.zip')
with zipfile.ZipFile(zip_path, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
    for name, data in sorted(payload.items()):
        entry = zipfile.ZipInfo('studioIntegration/' + name, date_time=(1980, 1, 1, 0, 0, 0))
        entry.create_system = 3
        entry.external_attr = (0o100644 << 16)
        entry.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(entry, data)
checksums = ''.join(hashlib.sha256(path.read_bytes()).hexdigest() + '  ' + path.name + '\n' for path in [zip_path, tar_path])
(output / 'SHA256SUMS.txt').write_text(checksums)
print(package)
