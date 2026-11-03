#!/bin/sh
# Confirm the selected PHP, phpize, and php-config are the same version.
# The top-level Makefile is the source of the expected version and API numbers.
set -eu

: "${PHP_VERSION:?PHP_VERSION is required}"
: "${PHP_API:?PHP_API is required}"
: "${ZEND_MODULE_API:?ZEND_MODULE_API is required}"
: "${ZEND_EXTENSION_API:?ZEND_EXTENSION_API is required}"
: "${PHP:?PHP is required}"
: "${PHPIZE:?PHPIZE is required}"
: "${PHP_CONFIG:?PHP_CONFIG is required}"

errors=''

note() {
	errors="${errors}
  - $1"
}

need_tool() {
	tool=$1
	label=$2
	if ! command -v "$tool" >/dev/null 2>&1; then
		note "$label not found: $tool"
		return 1
	fi
	return 0
}

field() {
	printf '%s\n' "$1" | sed -n "s/^$2:[[:space:]]*//p" | head -n 1
}

info_field() {
	printf '%s\n' "$1" | sed -n "s/^$2 =>[[:space:]]*//p" | head -n 1
}

version_prefix_matches() {
	version=$1
	case "$version" in
		"$PHP_VERSION"|"$PHP_VERSION".*) return 0 ;;
		*) return 1 ;;
	esac
}

php_path=$(command -v "$PHP" 2>/dev/null || true)
phpize_path=$(command -v "$PHPIZE" 2>/dev/null || true)
php_config_path=$(command -v "$PHP_CONFIG" 2>/dev/null || true)

need_tool "$PHP" "PHP binary" || true
need_tool "$PHPIZE" "phpize" || true
need_tool "$PHP_CONFIG" "php-config" || true

php_version='unavailable'
php_release='unavailable'
php_api='unavailable'
php_extension_api='unavailable'
zend_extension_api='unavailable'
phpize_text=''
phpize_version='unavailable'
phpize_api='unavailable'
phpize_module_api='unavailable'
phpize_extension_api='unavailable'
php_config_version='unavailable'
php_config_api='unavailable'
extension_dir='unavailable'

if [ -n "$php_path" ]; then
	if php_version=$("$PHP" -n -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;'); then
		:
	else
		php_version='unavailable'
		note "failed to execute $PHP"
	fi
	if php_release=$("$PHP" -n -r 'echo PHP_VERSION;'); then
		:
	else
		php_release='unavailable'
		note "failed to read the version from $PHP"
	fi
	if php_info=$("$PHP" -n -i); then
		php_api=$(info_field "$php_info" "PHP API")
		php_extension_api=$(info_field "$php_info" "PHP Extension")
		zend_extension_api=$(info_field "$php_info" "Zend Extension")
		[ -n "$php_api" ] || php_api='unavailable'
		[ -n "$php_extension_api" ] || php_extension_api='unavailable'
		[ -n "$zend_extension_api" ] || zend_extension_api='unavailable'
	else
		note "failed to read phpinfo from $PHP"
	fi
fi

if [ -n "$phpize_path" ]; then
	if phpize_text=$("$PHPIZE" --version); then
		phpize_version=$(field "$phpize_text" "PHP Version")
		phpize_api=$(field "$phpize_text" "PHP Api Version")
		phpize_module_api=$(field "$phpize_text" "Zend Module Api No")
		phpize_extension_api=$(field "$phpize_text" "Zend Extension Api No")
		[ -n "$phpize_version" ] || phpize_version='unavailable'
		[ -n "$phpize_api" ] || phpize_api='unavailable'
		[ -n "$phpize_module_api" ] || phpize_module_api='unavailable'
		[ -n "$phpize_extension_api" ] || phpize_extension_api='unavailable'
	else
		note "failed to execute $PHPIZE --version"
	fi
fi

if [ -n "$php_config_path" ]; then
	if php_config_version=$("$PHP_CONFIG" --version); then
		:
	else
		php_config_version='unavailable'
		note "failed to execute $PHP_CONFIG --version"
	fi
	if php_config_api=$("$PHP_CONFIG" --phpapi); then
		:
	else
		php_config_api='unavailable'
		note "failed to execute $PHP_CONFIG --phpapi"
	fi
	if extension_dir=$("$PHP_CONFIG" --extension-dir); then
		:
	else
		extension_dir='unavailable'
		note "failed to execute $PHP_CONFIG --extension-dir"
	fi
fi

printf 'Rephper build configuration\n'
printf '  Selected PHP version:          %s\n' "$PHP_VERSION"
printf '  PHP binary:                    %s\n' "${php_path:-$PHP}"
printf '  PHP binary version:            %s (%s)\n' "$php_release" "$php_version"
printf '  Configured PHP API:            %s\n' "$PHP_API"
printf '  Detected PHP API:              %s\n' "$php_api"
printf '  phpize PHP API:                %s\n' "$phpize_api"
printf '  php-config PHP API:            %s\n' "$php_config_api"
printf '  Configured Zend module API:    %s\n' "$ZEND_MODULE_API"
printf '  Detected Zend module API:      %s\n' "$php_extension_api"
printf '  phpize Zend module API:        %s\n' "$phpize_module_api"
printf '  Configured Zend extension API: %s\n' "$ZEND_EXTENSION_API"
printf '  Detected Zend extension API:   %s\n' "$zend_extension_api"
printf '  phpize Zend extension API:     %s\n' "$phpize_extension_api"
printf '  phpize:                        %s\n' "${phpize_path:-$PHPIZE}"
printf '  phpize version:                %s\n' "$phpize_version"
printf '  php-config:                    %s\n' "${php_config_path:-$PHP_CONFIG}"
printf '  php-config version:            %s\n' "$php_config_version"
printf '  Extension directory:           %s\n' "$extension_dir"

if [ "$php_version" != "$PHP_VERSION" ]; then
	note "PHP binary $PHP is $php_version, expected $PHP_VERSION"
fi
if ! version_prefix_matches "$php_release"; then
	note "PHP binary release $php_release is not $PHP_VERSION.x"
fi
if [ "$phpize_version" != "$PHP_VERSION" ]; then
	note "phpize $PHPIZE reports PHP $phpize_version, expected $PHP_VERSION"
fi
if ! version_prefix_matches "$php_config_version"; then
	note "php-config $PHP_CONFIG reports PHP $php_config_version, expected $PHP_VERSION.x"
fi
if [ "$php_api" != "$PHP_API" ] || [ "$phpize_api" != "$PHP_API" ] || [ "$php_config_api" != "$PHP_API" ]; then
	note "PHP API mismatch: configured $PHP_API, binary $php_api, phpize $phpize_api, php-config $php_config_api"
fi
if [ "$php_extension_api" != "$ZEND_MODULE_API" ] || [ "$phpize_module_api" != "$ZEND_MODULE_API" ]; then
	note "Zend module API mismatch: configured $ZEND_MODULE_API, binary $php_extension_api, phpize $phpize_module_api"
fi
if [ "$zend_extension_api" != "$ZEND_EXTENSION_API" ] || [ "$phpize_extension_api" != "$ZEND_EXTENSION_API" ]; then
	note "Zend extension API mismatch: configured $ZEND_EXTENSION_API, binary $zend_extension_api, phpize $phpize_extension_api"
fi

if [ -n "$errors" ]; then
	printf '\nPHP toolchain check failed:%s\n' "$errors"
	exit 1
fi

printf '  Status:                        OK\n'
