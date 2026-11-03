#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "zend_extensions.h"

#include "php_rephper.h"

/*
 * ZEND_EXT_API is not provided by the public Zend headers. It lives in the
 * opcache private headers, but ZEND_EXTENSION() still requires it so the
 * version symbol is visible to dlsym().
 */
#ifndef ZEND_EXT_API
# ifdef ZEND_WIN32
#  define ZEND_EXT_API __declspec(dllexport)
# elif defined(__GNUC__) && __GNUC__ >= 4
#  define ZEND_EXT_API __attribute__((visibility("default")))
# else
#  define ZEND_EXT_API
# endif
#endif

#ifndef REPHPER_EXPECTED_PHP_API
# error Rephper must be built through the top-level Makefile
#endif

#ifndef REPHPER_EXPECTED_ZEND_MODULE_API
# error Rephper must be built through the top-level Makefile
#endif

#ifndef REPHPER_EXPECTED_ZEND_EXTENSION_API
# error Rephper must be built through the top-level Makefile
#endif

#if PHP_API_VERSION != REPHPER_EXPECTED_PHP_API
# error PHP API does not match the Rephper build configuration
#endif

#if ZEND_MODULE_API_NO != REPHPER_EXPECTED_ZEND_MODULE_API
# error Zend module API does not match the Rephper build configuration
#endif

#if ZEND_EXTENSION_API_NO != REPHPER_EXPECTED_ZEND_EXTENSION_API
# error Zend extension API does not match the Rephper build configuration
#endif

ZEND_EXTENSION();

static void rephper_op_array_handler(zend_op_array *op_array)
{
	if (op_array == NULL) {
		return;
	}

	rephper_inspect_op_array(op_array);
}

ZEND_EXT_API zend_extension zend_extension_entry = {
	"Rephper",
	"0.1.0",
	"Rephper",
	"",
	"Copyright (c) Rephper",
	NULL, /* startup */
	NULL, /* shutdown */
	NULL, /* activate */
	NULL, /* deactivate */
	NULL, /* message_handler */
	rephper_op_array_handler,
	NULL, /* statement_handler */
	NULL, /* fcall_begin_handler */
	NULL, /* fcall_end_handler */
	NULL, /* op_array_ctor */
	NULL, /* op_array_dtor */
	STANDARD_ZEND_EXTENSION_PROPERTIES
};
