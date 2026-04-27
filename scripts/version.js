#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const rootDir = path.resolve(__dirname, '..');

const FILES = {
  version: 'VERSION',
  packageJson: 'package.json',
  plugin: 'wechat-article-importer.php',
  readmeTxt: 'readme.txt',
  readmeMd: 'README.md',
  translationCatalogs: [
    'languages/wechat-article-importer.pot',
    'languages/wechat-article-importer-en_US.po',
    'languages/wechat-article-importer-zh_CN.po',
  ],
  compiledTranslations: [
    ['languages/wechat-article-importer-en_US.po', 'languages/wechat-article-importer-en_US.mo'],
    ['languages/wechat-article-importer-zh_CN.po', 'languages/wechat-article-importer-zh_CN.mo'],
  ],
};

const VERSION_RE = /^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/;

function usage(exitCode = 0) {
  const stream = exitCode === 0 ? process.stdout : process.stderr;
  stream.write(`Usage: node scripts/version.js <command> [args]\n\nCommands:\n  current\n      Print the canonical version from VERSION.\n\n  check [--tag <vX.Y.Z>]\n      Verify every version metadata copy matches VERSION and that the\n      current readme.txt changelog contains a section for VERSION.\n\n  set <version> [--changelog-file <path>]\n      Update VERSION and all mirrored version metadata. If the readme.txt\n      changelog has no section for the new version, --changelog-file is\n      required and is inserted as the new top changelog entry.\n\n  release-notes [version]\n      Print GitHub release notes extracted from readme.txt. Defaults to\n      VERSION.\n`);
  process.exit(exitCode);
}

function filePath(relativePath) {
  return path.join(rootDir, relativePath);
}

function read(relativePath) {
  return fs.readFileSync(filePath(relativePath), 'utf8');
}

function write(relativePath, contents) {
  fs.writeFileSync(filePath(relativePath), contents, 'utf8');
}

function fail(message) {
  console.error(message);
  process.exit(1);
}

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function normalizeVersion(rawVersion) {
  const version = String(rawVersion || '').trim().replace(/^v(?=\d)/i, '');
  if (!VERSION_RE.test(version)) {
    fail(`Invalid version "${rawVersion}". Expected semantic version X.Y.Z with optional prerelease suffix.`);
  }
  return version;
}

function currentVersion() {
  return normalizeVersion(read(FILES.version));
}

function replaceOrFail(contents, pattern, replacement, label) {
  if (!pattern.test(contents)) {
    fail(`Unable to update ${label}. Pattern was not found.`);
  }
  return contents.replace(pattern, replacement);
}

function readVersions() {
  const packageJson = JSON.parse(read(FILES.packageJson));
  const plugin = read(FILES.plugin);
  const readmeTxt = read(FILES.readmeTxt);
  const readmeMd = read(FILES.readmeMd);

  const versions = {
    'VERSION': currentVersion(),
    'package.json version': packageJson.version,
    'plugin header Version': plugin.match(/^\s*\* Version:\s*(.+)$/m)?.[1]?.trim(),
    'WAI_VERSION': plugin.match(/const\s+WAI_VERSION\s*=\s*'([^']+)'\s*;/)?.[1],
    'readme.txt Stable tag': readmeTxt.match(/^Stable tag:\s*(.+)$/m)?.[1]?.trim(),
    'README translation package version': readmeMd.match(/--package-version='([^']+)'/)?.[1],
  };

  for (const catalog of FILES.translationCatalogs) {
    versions[`${catalog} Project-Id-Version`] = read(catalog).match(
      /^"Project-Id-Version:\s*WeChat Article Importer\s+([^\\]+)\\n"$/m
    )?.[1]?.trim();
  }

  return versions;
}

function assertChangelogSection(readmeTxt, version) {
  const sectionPattern = new RegExp(`^= ${escapeRegExp(version)} =$`, 'm');
  if (!sectionPattern.test(readmeTxt)) {
    fail(`readme.txt is missing a changelog section for ${version}.`);
  }
}

function checkVersion(options = {}) {
  const versions = readVersions();
  const missing = Object.entries(versions).filter(([, version]) => !version);
  if (missing.length > 0) {
    console.error('Unable to read version metadata:');
    for (const [name] of missing) {
      console.error(`- ${name}`);
    }
    process.exit(1);
  }

  const expected = normalizeVersion(versions.VERSION);
  const mismatches = Object.entries(versions).filter(([, version]) => version !== expected);
  if (mismatches.length > 0) {
    console.error(`Version metadata must match VERSION ${expected}:`);
    for (const [name, version] of mismatches) {
      console.error(`- ${name}: ${version}`);
    }
    process.exit(1);
  }

  if (options.tag) {
    const tagVersion = normalizeVersion(options.tag);
    if (tagVersion !== expected) {
      fail(`Git tag ${options.tag} does not match VERSION ${expected}.`);
    }
  }

  assertChangelogSection(read(FILES.readmeTxt), expected);
  console.log(`Version metadata is consistent at ${expected}.`);
}

function parseArgs(args) {
  const parsed = { _: [] };
  for (let index = 0; index < args.length; index++) {
    const arg = args[index];
    if (arg === '--tag') {
      parsed.tag = args[++index];
    } else if (arg === '--changelog-file') {
      parsed.changelogFile = args[++index];
    } else if (arg === '--help' || arg === '-h') {
      usage(0);
    } else if (arg.startsWith('--')) {
      fail(`Unknown option: ${arg}`);
    } else {
      parsed._.push(arg);
    }
  }
  return parsed;
}

function formatChangelog(rawChangelog) {
  const lines = String(rawChangelog || '')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => {
      if (line.startsWith('* ')) {
        return line;
      }
      if (line.startsWith('- ')) {
        return `* ${line.slice(2).trim()}`;
      }
      return `* ${line}`;
    });

  if (lines.length === 0) {
    fail('A non-empty changelog entry is required for a new version.');
  }

  return lines.join('\n');
}

function insertChangelogIfMissing(readmeTxt, version, changelogFile) {
  const sectionPattern = new RegExp(`^= ${escapeRegExp(version)} =$`, 'm');
  if (sectionPattern.test(readmeTxt)) {
    return readmeTxt;
  }

  if (!changelogFile) {
    fail(`readme.txt has no ${version} changelog section. Pass --changelog-file to insert one.`);
  }

  const changelog = formatChangelog(fs.readFileSync(path.resolve(changelogFile), 'utf8'));
  return replaceOrFail(
    readmeTxt,
    /(== Changelog ==\n\n)/,
    `$1= ${version} =\n${changelog}\n\n`,
    'readme.txt changelog'
  );
}

function setVersion(versionArg, options = {}) {
  const version = normalizeVersion(versionArg);

  write(FILES.version, `${version}\n`);

  const packageJson = JSON.parse(read(FILES.packageJson));
  packageJson.version = version;
  write(FILES.packageJson, `${JSON.stringify(packageJson, null, 2)}\n`);

  let plugin = read(FILES.plugin);
  plugin = replaceOrFail(plugin, /^(\s*\* Version:\s*).+$/m, `$1${version}`, 'plugin header version');
  plugin = replaceOrFail(plugin, /const\s+WAI_VERSION\s*=\s*'[^']+'\s*;/, `const WAI_VERSION = '${version}';`, 'WAI_VERSION constant');
  write(FILES.plugin, plugin);

  let readmeTxt = read(FILES.readmeTxt);
  readmeTxt = replaceOrFail(readmeTxt, /^(Stable tag:\s*).+$/m, `$1${version}`, 'readme.txt stable tag');
  readmeTxt = insertChangelogIfMissing(readmeTxt, version, options.changelogFile);
  write(FILES.readmeTxt, readmeTxt);

  let readmeMd = read(FILES.readmeMd);
  readmeMd = replaceOrFail(readmeMd, /--package-version='[^']+'/g, `--package-version='${version}'`, 'README package-version examples');
  write(FILES.readmeMd, readmeMd);

  for (const catalog of FILES.translationCatalogs) {
    const updatedCatalog = replaceOrFail(
      read(catalog),
      /^("Project-Id-Version:\s*WeChat Article Importer\s+)[^\\]+(\\n")$/m,
      `$1${version}$2`,
      `${catalog} Project-Id-Version`
    );
    write(catalog, updatedCatalog);
  }

  compileTranslations();
  checkVersion();
}

function compileTranslations() {
  for (const [poFile, moFile] of FILES.compiledTranslations) {
    const result = spawnSync(
      'msgfmt',
      ['--check', '--check-format', `--output-file=${filePath(moFile)}`, filePath(poFile)],
      { stdio: 'inherit' }
    );
    if (result.error) {
      fail(`msgfmt is required to compile ${moFile}: ${result.error.message}`);
    }
    if (result.status !== 0) {
      fail(`Failed to compile ${moFile} from ${poFile}.`);
    }
  }
}

function extractReleaseNotes(versionArg) {
  const version = versionArg ? normalizeVersion(versionArg) : currentVersion();
  const readmeTxt = read(FILES.readmeTxt);
  const sectionPattern = new RegExp(`(?:^|\\n)= ${escapeRegExp(version)} =\\n([\\s\\S]*?)(?=\\n= [^\\n=]+ =\\n|\\s*$)`);
  const match = readmeTxt.match(sectionPattern);
  if (!match) {
    fail(`Unable to find release notes for ${version} in readme.txt.`);
  }

  const body = match[1].trim();
  if (!body) {
    fail(`Release notes for ${version} are empty.`);
  }

  process.stdout.write(`# WeChat Article Importer ${version}\n\n${body}\n`);
}

const [command, ...rest] = process.argv.slice(2);
const args = parseArgs(rest);

switch (command) {
  case 'current':
    console.log(currentVersion());
    break;
  case 'check':
    checkVersion({ tag: args.tag });
    break;
  case 'set':
    if (args._.length !== 1) {
      usage(1);
    }
    setVersion(args._[0], { changelogFile: args.changelogFile });
    break;
  case 'release-notes':
    if (args._.length > 1) {
      usage(1);
    }
    extractReleaseNotes(args._[0]);
    break;
  default:
    usage(command ? 1 : 0);
}
