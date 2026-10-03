"""Checks source files changed by this revision; generated assets and dependencies are excluded."""
import os
import subprocess

base = os.environ.get('CI_BASE_SHA', '')
if not base or set(base) == {'0'}:
    base = 'HEAD^'
files = subprocess.check_output(['git', 'diff', '--name-only', '--diff-filter=ACMR', base, 'HEAD'], text=True).splitlines()
php = [path for path in files if path.endswith('.php') and path.startswith(('app/', 'routes/', 'database/', 'lang/', 'scripts/', 'tests/'))]
js = [path for path in files if path.endswith(('.vue', '.js')) and path.startswith(('resources/js/', 'tests/JavaScript/'))]
if php:
    subprocess.run(['php', 'vendor/bin/pint', '--test', *php], check=True)
if js:
    subprocess.run(['node', 'node_modules/eslint/bin/eslint.js', *js], check=True)
    subprocess.run(['node', 'node_modules/prettier/bin/prettier.cjs', '--check', *js], check=True)
print(f'Changed-source style checks passed: {len(php)} PHP, {len(js)} JavaScript/Vue files.')
