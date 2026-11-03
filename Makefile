# Rephper build configuration.
#
# PHP_VERSION selects the toolchain (php8.4, phpize8.4, php-config8.4).
# API numbers are the compatibility metadata for that version. They are
# checked against the selected binaries before anything is compiled.
# Add a PHP version here only when extension/src/Compatibility has an
# implementation for it and extension/config.m4 accepts its API.

PHP_VERSION ?= 8.4

ifeq ($(PHP_VERSION),8.4)
PHP_API ?= 20240924
ZEND_MODULE_API ?= 20240924
ZEND_EXTENSION_API ?= 420240924
else
$(error Rephper has no build metadata for PHP $(PHP_VERSION))
endif

PHP ?= php$(PHP_VERSION)
PHPIZE ?= phpize$(PHP_VERSION)
PHP_CONFIG ?= php-config$(PHP_VERSION)
COMPOSER ?= composer

ROOT := $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))
EXT_DIR := $(ROOT)/extension
EXTENSION := $(EXT_DIR)/modules/rephper.so

EXTRA_CFLAGS := -Wall -Wextra -DREPHPER_EXPECTED_PHP_API=$(PHP_API) \
	-DREPHPER_EXPECTED_ZEND_MODULE_API=$(ZEND_MODULE_API) \
	-DREPHPER_EXPECTED_ZEND_EXTENSION_API=$(ZEND_EXTENSION_API)

.PHONY: info check-php build test test-unit test-integration clean install

info: check-php

check-php:
	@PHP_VERSION='$(PHP_VERSION)' \
		PHP_API='$(PHP_API)' \
		ZEND_MODULE_API='$(ZEND_MODULE_API)' \
		ZEND_EXTENSION_API='$(ZEND_EXTENSION_API)' \
		PHP='$(PHP)' \
		PHPIZE='$(PHPIZE)' \
		PHP_CONFIG='$(PHP_CONFIG)' \
		'$(ROOT)/scripts/verify-php.sh'

build: check-php
	cd '$(EXT_DIR)' && '$(PHPIZE)' --clean
	cd '$(EXT_DIR)' && '$(PHPIZE)'
	cd '$(EXT_DIR)' && \
		REPHPER_PHP_VERSION='$(PHP_VERSION)' \
		REPHPER_PHP_API='$(PHP_API)' \
		REPHPER_ZEND_MODULE_API='$(ZEND_MODULE_API)' \
		REPHPER_ZEND_EXTENSION_API='$(ZEND_EXTENSION_API)' \
		./configure --with-php-config='$(PHP_CONFIG)' --enable-rephper
	$(MAKE) -C '$(EXT_DIR)' EXTRA_CFLAGS='$(EXTRA_CFLAGS)'

test: build
	REPHPER_PHP='$(PHP)' REPHPER_EXTENSION='$(EXTENSION)' \
		'$(COMPOSER)' test --no-interaction

test-unit:
	'$(COMPOSER)' test:unit --no-interaction

test-integration: build
	REPHPER_PHP='$(PHP)' REPHPER_EXTENSION='$(EXTENSION)' \
		'$(COMPOSER)' test:integration --no-interaction

clean:
	@if [ -f '$(EXT_DIR)/Makefile' ]; then $(MAKE) -C '$(EXT_DIR)' clean; fi
	cd '$(EXT_DIR)' && '$(PHPIZE)' --clean

install: build
	$(MAKE) -C '$(EXT_DIR)' install
