import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest
spec=importlib.util.spec_from_file_location('package_release',Path(__file__).parents[1]/'package_release.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)

class PackagingTests(unittest.TestCase):
    def test_openapi_only_and_no_environment_or_cache(self):
        with tempfile.TemporaryDirectory() as d:
            source=Path(d)/'source';source.mkdir()
            for rel in ['artisan','bootstrap/app.php','vendor/autoload.php','docs/openapi.yaml','docs/CICD.md','.env','.env.production','bootstrap/cache/config.php','tests/secret-fixture']:
                p=source/rel;p.parent.mkdir(parents=True,exist_ok=True);p.write_text('fixture')
            out=Path(d)/'release.tgz';module.package('api',source,out,'a'*40)
            with tarfile.open(out,'r:gz') as t:
                names=set(t.getnames())
                self.assertIn('docs/openapi.yaml',names)
                self.assertNotIn('docs/CICD.md',names)
                self.assertNotIn('.env',names)
                self.assertNotIn('.env.production',names)
                self.assertNotIn('bootstrap/cache/config.php',names)
                self.assertNotIn('tests/secret-fixture',names)
                self.assertEqual(json.load(t.extractfile('.ll-build.json'))['commit'],'a'*40)
                self.assertTrue(all(m.uid==0 and m.gid==0 and not m.mode & 0o022 for m in t if not m.issym()))
            self.assertRegex(out.with_suffix('.sha256').read_text(),r'^[0-9a-f]{64}\n$')

    def test_missing_runtime_files_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            with self.assertRaises(ValueError):module.package('web',d,Path(d)/'release.tgz','a'*40)

    def test_web_runtime_and_public_assets_preserved(self):
        with tempfile.TemporaryDirectory() as d:
            source=Path(d)/'source';source.mkdir()
            for rel in ['package.json','node_modules/next/dist/bin/next','.next/BUILD_ID','.next/static/app.js','.next/cache/secret','.env.local','docs/internal.md']:
                p=source/rel;p.parent.mkdir(parents=True,exist_ok=True);p.write_text('fixture')
            out=Path(d)/'release.tgz';module.package('web',source,out,'b'*40)
            with tarfile.open(out,'r:gz') as t:
                names=set(t.getnames());self.assertIn('.next/static/app.js',names);self.assertNotIn('.next/cache/secret',names);self.assertNotIn('.env.local',names);self.assertNotIn('docs/internal.md',names)

if __name__=='__main__':unittest.main(verbosity=2)
