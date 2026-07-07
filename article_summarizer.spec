# -*- mode: python ; coding: utf-8 -*-
from PyInstaller.utils.hooks import collect_all, collect_submodules

block_cipher = None

playwright_datas, playwright_binaries, playwright_hiddenimports = collect_all('playwright')

a = Analysis(
    ['main.py'],
    pathex=[],
    binaries=playwright_binaries,
    datas=playwright_datas,
    hiddenimports=[
        'playwright',
        'playwright.sync_api',
        'playwright._impl',
        'playwright._impl._driver',
        'xml.etree.ElementTree',
    ] + collect_submodules('playwright') + playwright_hiddenimports,
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=[],
    win_no_prefer_redirects=False,
    win_private_assemblies=False,
    cipher=block_cipher,
    noarchive=False,
)

pyz = PYZ(a.pure, a.zipped_data, cipher=block_cipher)

exe = EXE(
    pyz,
    a.scripts,
    [],
    exclude_binaries=True,
    name='文章自动总结工具',
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=True,
    console=False,
    disable_windowed_traceback=False,
    argv_emulation=False,
    target_arch=None,
    codesign_identity=None,
    entitlements_file=None,
    icon=None,
)

coll = COLLECT(
    exe,
    a.binaries,
    a.zipfiles,
    a.datas,
    strip=False,
    upx=True,
    upx_exclude=[],
    name='文章自动总结工具',
)
