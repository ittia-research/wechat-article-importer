#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root_dir="$(cd "${script_dir}/.." && pwd)"

require_command() {
    local command_name="$1"
    if ! command -v "${command_name}" >/dev/null 2>&1; then
        echo "${command_name} is required for validation." >&2
        exit 1
    fi
}

require_command bash
require_command php
require_command node
require_command msgfmt

cd "${root_dir}"

bash -n scripts/check.sh
bash -n scripts/build-wordpress-package.sh
node -e "JSON.parse(require('fs').readFileSync('package.json', 'utf8'))"
node --check scripts/version.js
node scripts/version.js check
php -l wechat-article-importer.php
node --check js/importer.js
msgfmt --check --check-format --output-file=/dev/null languages/wechat-article-importer-en_US.po
msgfmt --check --check-format --output-file=/dev/null languages/wechat-article-importer-zh_CN.po
php tests/run.php
