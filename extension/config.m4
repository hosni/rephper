PHP_ARG_ENABLE([rephper],
  [whether to enable Rephper],
  [AS_HELP_STRING([--enable-rephper], [Enable the Rephper Zend extension])],
  [yes])

if test "$PHP_REPHPER" != "no"; then
  if test -z "$REPHPER_PHP_VERSION"; then
    AC_MSG_ERROR([REPHPER_PHP_VERSION is not set. Build Rephper with the top-level Makefile.])
  fi
  if test -z "$REPHPER_PHP_API"; then
    AC_MSG_ERROR([REPHPER_PHP_API is not set. Build Rephper with the top-level Makefile.])
  fi
  if test -z "$REPHPER_ZEND_MODULE_API"; then
    AC_MSG_ERROR([REPHPER_ZEND_MODULE_API is not set. Build Rephper with the top-level Makefile.])
  fi
  if test -z "$REPHPER_ZEND_EXTENSION_API"; then
    AC_MSG_ERROR([REPHPER_ZEND_EXTENSION_API is not set. Build Rephper with the top-level Makefile.])
  fi
  if test -z "$PHP_CONFIG"; then
    AC_MSG_ERROR([php-config was not provided. Configure through the top-level Makefile.])
  fi

  AC_MSG_CHECKING([php-config version against Rephper PHP $REPHPER_PHP_VERSION])
  rephper_php_version=`$PHP_CONFIG --version`
  AC_MSG_RESULT([$rephper_php_version])
  case "$rephper_php_version" in
    ${REPHPER_PHP_VERSION}|${REPHPER_PHP_VERSION}.*) ;;
    *)
      AC_MSG_ERROR([php-config reports PHP $rephper_php_version, expected ${REPHPER_PHP_VERSION}.x])
      ;;
  esac

  AC_MSG_CHECKING([php-config PHP API against Rephper API $REPHPER_PHP_API])
  rephper_phpapi=`$PHP_CONFIG --phpapi`
  AC_MSG_RESULT([$rephper_phpapi])
  if test "$rephper_phpapi" != "$REPHPER_PHP_API"; then
    AC_MSG_ERROR([php-config PHP API $rephper_phpapi does not match configured API $REPHPER_PHP_API])
  fi

  AC_MSG_CHECKING([php-config version id])
  rephper_vernum=`$PHP_CONFIG --vernum`
  AC_MSG_RESULT([$rephper_vernum])
  rephper_major=`echo "$REPHPER_PHP_VERSION" | cut -d. -f1`
  rephper_minor=`echo "$REPHPER_PHP_VERSION" | cut -d. -f2`
  rephper_min=`expr "$rephper_major" \* 10000 + "$rephper_minor" \* 100`
  rephper_max=`expr "$rephper_min" + 100`
  if test "$rephper_vernum" -lt "$rephper_min" || test "$rephper_vernum" -ge "$rephper_max"; then
    AC_MSG_ERROR([php-config vernum $rephper_vernum is outside PHP ${REPHPER_PHP_VERSION}.x])
  fi

  dnl Implemented engine APIs. Add a branch only together with a C implementation
  dnl and the matching literal source list passed to PHP_NEW_EXTENSION.
  case "$REPHPER_PHP_API" in
    20240924) ;;
    *)
      AC_MSG_ERROR([Rephper has no implementation for PHP API $REPHPER_PHP_API])
      ;;
  esac

  AC_DEFINE([HAVE_REPHPER], [1], [Rephper opcode inspection])

  dnl PHP_NEW_EXTENSION records source names while configure is generated, so
  dnl the list is literal. The case above rejects every other API.
  PHP_NEW_EXTENSION([rephper], [rephper.c src/Common/json.c src/Compatibility/php84/inspect.c src/Compatibility/php84/export.c], [$ext_shared])
fi
