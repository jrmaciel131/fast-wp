"""Builds build/fast-wp.zip (fast-wp/ folder with forward slashes, ready for WordPress).

Usage: python bin/build-zip.py
"""
import fnmatch
import os
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'build', 'fast-wp.zip')

with open(os.path.join(ROOT, '.distignore'), encoding='utf-8') as f:
    ignore = [line.strip().rstrip('/') for line in f if line.strip() and not line.startswith('#')]


def skipped(rel):
    parts = rel.split('/')
    return any(fnmatch.fnmatch(p, pat) for p in parts for pat in ignore)


os.makedirs(os.path.dirname(OUT), exist_ok=True)
count = 0
with zipfile.ZipFile(OUT, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(ROOT):
        rel_root = os.path.relpath(root, ROOT).replace(os.sep, '/')
        if rel_root != '.' and skipped(rel_root):
            dirs[:] = []
            continue
        for name in files:
            rel = (name if rel_root == '.' else rel_root + '/' + name)
            if skipped(rel):
                continue
            z.write(os.path.join(root, name), 'fast-wp/' + rel)
            count += 1
print(f'{OUT}: {count} files')
