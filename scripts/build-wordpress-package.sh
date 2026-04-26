#!/usr/bin/env bash
set -euo pipefail

plugin_slug="wechat-article-importer"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root_dir="$(cd "${script_dir}/.." && pwd)"
zip_path="${root_dir}/${plugin_slug}.zip"
staging_dir="$(mktemp -d)"
package_dir="${staging_dir}/${plugin_slug}"

cleanup() {
    rm -rf "${staging_dir}"
}
trap cleanup EXIT

require_command() {
    local command_name="$1"
    if ! command -v "${command_name}" >/dev/null 2>&1; then
        echo "${command_name} is required to build the WordPress package." >&2
        exit 1
    fi
}

assert_version_consistency() {
    node - "${root_dir}" <<'NODE'
const fs = require('fs');
const path = require('path');

const rootDir = process.argv[2];
const read = (relativePath) => fs.readFileSync(path.join(rootDir, relativePath), 'utf8');
const packageJson = JSON.parse(read('package.json'));
const pluginPhp = read('wechat-article-importer.php');
const readmeTxt = read('readme.txt');
const readmeMd = read('README.md');

const versions = {
    'package.json version': packageJson.version,
    'plugin header Version': pluginPhp.match(/^\s*\* Version:\s*(.+)$/m)?.[1]?.trim(),
    'WAI_VERSION': pluginPhp.match(/const\s+WAI_VERSION\s*=\s*'([^']+)'\s*;/)?.[1],
    'readme.txt Stable tag': readmeTxt.match(/^Stable tag:\s*(.+)$/m)?.[1]?.trim(),
    'README translation package version': readmeMd.match(/--package-version='([^']+)'/)?.[1],
};

const missing = Object.entries(versions).filter(([, version]) => !version);
if (missing.length > 0) {
    console.error('Unable to read version metadata:');
    for (const [name] of missing) {
        console.error(`- ${name}`);
    }
    process.exit(1);
}

const expected = versions['plugin header Version'];
const mismatches = Object.entries(versions).filter(([, version]) => version !== expected);
if (mismatches.length > 0) {
    console.error(`Version metadata must match plugin header version ${expected}:`);
    for (const [name, version] of mismatches) {
        console.error(`- ${name}: ${version}`);
    }
    process.exit(1);
}
NODE
}

is_dev_only_path() {
    local relative_path="$1"
    case "${relative_path}" in
        .*) return 0 ;;
        */.*) return 0 ;;
        package.json|package-lock.json|npm-shrinkwrap.json) return 0 ;;
        scripts/*|tests/*) return 0 ;;
        node_modules/*|vendor/*|coverage/*|build/*|dist/*) return 0 ;;
        *.zip) return 0 ;;
        *.log) return 0 ;;
    esac
    return 1
}

collect_package_files() {
    local candidate
    local candidates=()
    package_files=()

    if git -C "${root_dir}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        while IFS= read -r -d '' candidate; do
            candidates+=("${candidate}")
        done < <(git -C "${root_dir}" ls-files -z)
    else
        candidates=(
            "wechat-article-importer.php"
            "readme.txt"
            "LICENSE"
            "README.md"
            "js/importer.js"
            "languages/wechat-article-importer.pot"
            "languages/wechat-article-importer-en_US.po"
            "languages/wechat-article-importer-en_US.mo"
            "languages/wechat-article-importer-zh_CN.po"
            "languages/wechat-article-importer-zh_CN.mo"
        )
    fi

    for candidate in "${candidates[@]}"; do
        if is_dev_only_path "${candidate}"; then
            continue
        fi
        if [[ -f "${root_dir}/${candidate}" ]]; then
            package_files+=("${candidate}")
        fi
    done
}

stage_package_files() {
    local relative_path
    local destination_dir

    if [[ "${#package_files[@]}" -eq 0 ]]; then
        echo "No package files were selected." >&2
        exit 1
    fi

    mkdir -p "${package_dir}"
    for relative_path in "${package_files[@]}"; do
        destination_dir="${package_dir}/$(dirname "${relative_path}")"
        mkdir -p "${destination_dir}"
        cp "${root_dir}/${relative_path}" "${package_dir}/${relative_path}"
    done
}

assert_staged_package() {
    local required_path
    local required_paths=(
        "wechat-article-importer.php"
        "readme.txt"
        "js/importer.js"
        "languages/wechat-article-importer.pot"
    )

    for required_path in "${required_paths[@]}"; do
        if [[ ! -f "${package_dir}/${required_path}" ]]; then
            echo "Package is missing required file: ${required_path}" >&2
            exit 1
        fi
    done
}

entry_exists() {
    local needle="$1"
    local entry
    for entry in "${zip_entries[@]}"; do
        if [[ "${entry}" == "${needle}" ]]; then
            return 0
        fi
    done
    return 1
}

assert_zip_contents() {
    local entry
    local required_path
    local forbidden_path
    local required_paths=(
        "${plugin_slug}/wechat-article-importer.php"
        "${plugin_slug}/readme.txt"
        "${plugin_slug}/js/importer.js"
        "${plugin_slug}/languages/wechat-article-importer.pot"
    )
    local forbidden_paths=(
        "${plugin_slug}/.editorconfig"
        "${plugin_slug}/.gitignore"
        "${plugin_slug}/package.json"
        "${plugin_slug}/scripts/"
        "${plugin_slug}/tests/"
    )

    mapfile -t zip_entries < <(unzip -Z1 "${zip_path}")

    for entry in "${zip_entries[@]}"; do
        if [[ "${entry}" != "${plugin_slug}/"* ]]; then
            echo "Unexpected ZIP entry outside ${plugin_slug}/: ${entry}" >&2
            exit 1
        fi
    done

    for required_path in "${required_paths[@]}"; do
        if ! entry_exists "${required_path}"; then
            echo "ZIP is missing required entry: ${required_path}" >&2
            exit 1
        fi
    done

    for forbidden_path in "${forbidden_paths[@]}"; do
        for entry in "${zip_entries[@]}"; do
            if [[ "${entry}" == "${forbidden_path}"* ]]; then
                echo "ZIP contains development-only entry: ${entry}" >&2
                exit 1
            fi
        done
    done
}

require_command node
require_command zip
require_command unzip

assert_version_consistency
collect_package_files
stage_package_files
assert_staged_package

rm -f "${zip_path}"
(
    cd "${staging_dir}"
    zip -qr "${zip_path}" "${plugin_slug}"
)
assert_zip_contents

printf 'Built WordPress package: %s (%s files)\n' "${zip_path}" "${#package_files[@]}"
