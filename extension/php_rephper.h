#ifndef PHP_REPHPER_H
#define PHP_REPHPER_H

/*
 * Include this header after php.h. The typedef below covers a translation
 * unit that has not included the Zend headers yet.
 */
#ifndef ZEND_COMPILE_H
typedef struct _zend_op_array zend_op_array;
#endif

void rephper_inspect_op_array(zend_op_array *op_array);
void rephper_export_reset(void);
void rephper_export_op_array(zend_op_array *op_array);
void rephper_export_namespaces(zend_ast *ast);
void rephper_export_finish(void);

#endif
