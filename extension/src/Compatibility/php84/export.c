#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "zend_compile.h"
#include "zend_vm_opcodes.h"
#include "zend_attributes.h"
#include "zend_extensions.h"
#include "zend_strtod.h"

#include <errno.h>
#include <fcntl.h>
#include <limits.h>
#include <math.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>

#include "php_rephper.h"
#include "opcodes.h"
#include "../../Common/json.h"

#if PHP_API_VERSION != 20240924
# error src/Compatibility/php84 implements PHP 8.4 (API 20240924) only
#endif

/*
 * IR format 1.
 *
 * op_array_handler runs at the start of pass_two(). This file copies the
 * compile-time op array into JSON before that pass rewrites operands, and
 * before request shutdown frees the compiler structures. The JSON contains
 * no pointers. ir_version describes this document. php.version describes the
 * compiler that produced it. They change independently.
 *
 * REPHPER_IR is the output path. It is read from the environment because
 * Rephper is a Zend extension and does not register a PHP module INI entry.
 * The text dump on stderr is unchanged. When the variable is unset, nothing
 * is written.
 */

#define REPHPER_IR_VERSION 1
#define REPHPER_SAFE_INTEGER 9007199254740991LL

typedef struct {
	const HashTable *stack[24];
	int depth;
} rephper_walk;

typedef struct {
	const zend_op_array *op_array;
	uint32_t id;
} rephper_seen_op;

typedef struct {
	uint32_t bit;
	const char *name;
} rephper_flag;

static rephper_json g_doc;
static rephper_json g_classes;
static bool g_ready = false;
static bool g_started = false;
static bool g_classes_captured = false;
static int g_mode = 0;
static char *g_path = NULL;
static rephper_seen_op *g_seen = NULL;
static uint32_t g_seen_count = 0;
static uint32_t g_seen_cap = 0;
static uint32_t g_next_id = 0;

/*
 * Namespace regions are recorded from the compiler AST, before opcodes exist.
 * CG(file_context).current_namespace is only the namespace still active when an
 * op array reaches pass_two(). A bracketed block clears it before the main
 * script gets there, and several unbracketed namespaces leave only the last
 * name. The AST walk is the compiler's own tree, not a source parse.
 */
typedef struct {
	uint32_t *lines;
	uint32_t count;
	uint32_t cap;
} rephper_lines;

typedef struct {
	char *name;
	uint32_t line_start;
	uint32_t line_end;
	char **functions;
	uint32_t function_count;
	uint32_t function_cap;
	rephper_lines *statements;
	uint32_t statement_count;
	uint32_t statement_cap;
} rephper_region;

static rephper_region *g_regions = NULL;
static uint32_t g_region_count = 0;
static uint32_t g_region_cap = 0;

static void rephper_export_ensure(void)
{
	if (g_ready) {
		return;
	}

	rephper_json_init(&g_doc);
	rephper_json_init(&g_classes);
	g_ready = true;
}

static bool rephper_export_enabled(void)
{
	const char *path;

	rephper_export_ensure();
	if (g_mode != 0) {
		return g_mode == 2;
	}

	path = getenv("REPHPER_IR");
	if (path == NULL || path[0] == '\0') {
		g_mode = 1;
		return false;
	}

	g_path = strdup(path);
	if (g_path == NULL) {
		g_doc.failed = true;
		g_mode = 1;
		return false;
	}

	g_mode = 2;
	return true;
}

static void rephper_regions_free(void)
{
	uint32_t i;
	uint32_t j;

	for (i = 0; i < g_region_count; i++) {
		free(g_regions[i].name);
		for (j = 0; j < g_regions[i].function_count; j++) {
			free(g_regions[i].functions[j]);
		}
		free(g_regions[i].functions);
		for (j = 0; j < g_regions[i].statement_count; j++) {
			free(g_regions[i].statements[j].lines);
		}
		free(g_regions[i].statements);
	}
	free(g_regions);
	g_regions = NULL;
	g_region_count = 0;
	g_region_cap = 0;
}

void rephper_export_reset(void)
{
	rephper_export_ensure();
	rephper_json_reset(&g_doc);
	rephper_json_reset(&g_classes);
	free(g_seen);
	g_seen = NULL;
	g_seen_count = 0;
	g_seen_cap = 0;
	free(g_path);
	g_path = NULL;
	g_started = false;
	g_classes_captured = false;
	g_mode = 0;
	g_next_id = 0;
	rephper_regions_free();
}

static bool rephper_walk_contains(const rephper_walk *walk, const HashTable *table)
{
	int i;

	for (i = 0; i < walk->depth; i++) {
		if (walk->stack[i] == table) {
			return true;
		}
	}

	return false;
}

static const zval *rephper_deref(const zval *value)
{
	int guard = 0;

	while (value != NULL && guard < 8) {
		if (Z_TYPE_P(value) == IS_INDIRECT) {
			value = Z_INDIRECT_P(value);
		} else if (Z_TYPE_P(value) == IS_REFERENCE) {
			value = Z_REFVAL_P(value);
		} else {
			break;
		}
		guard++;
	}

	return value;
}

static void rephper_write_zval(rephper_json *json, const zval *value, rephper_walk *walk);

static const char *rephper_ast_kind_name(zend_ast_kind kind)
{
	switch (kind) {
		case ZEND_AST_ZVAL:
			return "ZVAL";
		case ZEND_AST_CONSTANT:
			return "CONSTANT";
		case ZEND_AST_BINARY_OP:
			return "BINARY_OP";
		case ZEND_AST_UNARY_OP:
			return "UNARY_OP";
		case ZEND_AST_UNARY_PLUS:
			return "UNARY_PLUS";
		case ZEND_AST_UNARY_MINUS:
			return "UNARY_MINUS";
		case ZEND_AST_ARRAY:
			return "ARRAY";
		case ZEND_AST_ARRAY_ELEM:
			return "ARRAY_ELEM";
		case ZEND_AST_DIM:
			return "DIM";
		case ZEND_AST_CONST:
			return "CONST";
		case ZEND_AST_CLASS_CONST:
			return "CLASS_CONST";
		case ZEND_AST_AND:
			return "AND";
		case ZEND_AST_OR:
			return "OR";
		case ZEND_AST_COALESCE:
			return "COALESCE";
		case ZEND_AST_CONDITIONAL:
			return "CONDITIONAL";
		case ZEND_AST_ASSIGN:
			return "ASSIGN";
		case ZEND_AST_GREATER:
			return "GREATER";
		case ZEND_AST_GREATER_EQUAL:
			return "GREATER_EQUAL";
		case ZEND_AST_ASSIGN_OP:
			return "ASSIGN_OP";
		case ZEND_AST_CAST:
			return "CAST";
		case ZEND_AST_CONST_ENUM_INIT:
			return "CONST_ENUM_INIT";
		default:
			return "unknown";
	}
}

static void rephper_write_ast(rephper_json *json, zend_ast *ast, rephper_walk *walk, int depth)
{
	uint32_t i;
	uint32_t children;

	if (ast == NULL || depth > 32) {
		rephper_json_null(json);
		return;
	}

	rephper_json_object_begin(json);
	rephper_json_key(json, "kind");
	rephper_json_cstr(json, rephper_ast_kind_name(ast->kind));
	rephper_json_key(json, "kind_number");
	rephper_json_u32(json, ast->kind);
	rephper_json_key(json, "line");
	rephper_json_u32(json, zend_ast_get_lineno(ast));
	rephper_json_key(json, "attr");
	rephper_json_u32(json, ast->attr);

	if ((ast->kind == ZEND_AST_BINARY_OP || ast->kind == ZEND_AST_UNARY_OP
			|| ast->kind == ZEND_AST_ASSIGN_OP || ast->kind == ZEND_AST_CAST)
		&& ast->attr <= ZEND_VM_LAST_OPCODE) {
		rephper_json_key(json, "attr_opcode");
		rephper_json_cstr(json, rephper_opcode_name((uint8_t) ast->attr));
	}

	if (ast->kind == ZEND_AST_ZVAL || ast->kind == ZEND_AST_CONSTANT) {
		zval *literal = &((zend_ast_zval *) ast)->val;

		rephper_json_key(json, "value");
		rephper_write_zval(json, literal, walk);
		rephper_json_object_end(json);
		return;
	}

	if (zend_ast_is_special(ast)) {
		rephper_json_key(json, "special");
		rephper_json_bool(json, true);
		rephper_json_object_end(json);
		return;
	}

	rephper_json_key(json, "children");
	rephper_json_array_begin(json);
	if (zend_ast_is_list(ast)) {
		zend_ast_list *list = zend_ast_get_list(ast);

		children = list->children;
		for (i = 0; i < children; i++) {
			rephper_json_element(json);
			rephper_write_ast(json, list->child[i], walk, depth + 1);
		}
	} else {
		children = zend_ast_get_num_children(ast);
		for (i = 0; i < children; i++) {
			rephper_json_element(json);
			rephper_write_ast(json, ast->child[i], walk, depth + 1);
		}
	}
	rephper_json_array_end(json);
	rephper_json_object_end(json);
}

static void rephper_write_int_value(rephper_json *json, zend_long value)
{
	char buffer[32];
	int length;

	rephper_json_object_begin(json);
	rephper_json_key(json, "type");
	rephper_json_cstr(json, "int");
	rephper_json_key(json, "value");
	if ((long long) value > REPHPER_SAFE_INTEGER || (long long) value < -REPHPER_SAFE_INTEGER) {
		length = snprintf(buffer, sizeof(buffer), ZEND_LONG_FMT, value);
		if (length < 0) {
			json->failed = true;
			rephper_json_null(json);
		} else {
			(void) rephper_json_bytes(json, buffer, (size_t) length);
		}
	} else {
		rephper_json_i64(json, (int64_t) value);
	}
	rephper_json_object_end(json);
}

static void rephper_write_float_value(rephper_json *json, double value)
{
	char buffer[64];

	rephper_json_object_begin(json);
	rephper_json_key(json, "type");
	rephper_json_cstr(json, "float");
	rephper_json_key(json, "value");
	if (zend_isnan(value)) {
		rephper_json_cstr(json, "NaN");
	} else if (!zend_finite(value)) {
		rephper_json_cstr(json, signbit(value) ? "-Infinity" : "Infinity");
	} else if (value == 0.0 && signbit(value)) {
		rephper_json_cstr(json, "-0");
	} else {
		zend_gcvt(value, 17, '.', 'e', buffer);
		rephper_json_splice(json, buffer, strlen(buffer));
	}
	rephper_json_object_end(json);
}

static void rephper_write_string_value(rephper_json *json, const char *text, size_t length)
{
	bool binary;

	rephper_json_object_begin(json);
	rephper_json_key(json, "type");
	rephper_json_cstr(json, "string");
	rephper_json_key(json, "value");
	binary = rephper_json_bytes(json, text, length);
	if (binary) {
		rephper_json_key(json, "binary");
		rephper_json_bool(json, true);
	}
	rephper_json_object_end(json);
}

static void rephper_write_zval(rephper_json *json, const zval *value, rephper_walk *walk)
{
	value = rephper_deref(value);
	if (value == NULL) {
		rephper_json_null(json);
		return;
	}

	switch (Z_TYPE_P(value)) {
		case IS_UNDEF:
			rephper_json_object_begin(json);
			rephper_json_key(json, "type");
			rephper_json_cstr(json, "undef");
			rephper_json_object_end(json);
			return;
		case IS_NULL:
			rephper_json_object_begin(json);
			rephper_json_key(json, "type");
			rephper_json_cstr(json, "null");
			rephper_json_object_end(json);
			return;
		case IS_FALSE:
		case IS_TRUE:
			rephper_json_object_begin(json);
			rephper_json_key(json, "type");
			rephper_json_cstr(json, "bool");
			rephper_json_key(json, "value");
			rephper_json_bool(json, Z_TYPE_P(value) == IS_TRUE);
			rephper_json_object_end(json);
			return;
		case IS_LONG:
			rephper_write_int_value(json, Z_LVAL_P(value));
			return;
		case IS_DOUBLE:
			rephper_write_float_value(json, Z_DVAL_P(value));
			return;
		case IS_STRING:
			rephper_write_string_value(json, Z_STRVAL_P(value), Z_STRLEN_P(value));
			return;
		case IS_ARRAY: {
			HashTable *table = Z_ARRVAL_P(value);
			zend_ulong index;
			zend_string *key;
			zval *item;

			rephper_json_object_begin(json);
			rephper_json_key(json, "type");
			rephper_json_cstr(json, "array");
			if (table == NULL || walk->depth >= (int) (sizeof(walk->stack) / sizeof(walk->stack[0]))
				|| rephper_walk_contains(walk, table)) {
				rephper_json_key(json, "recursive");
				rephper_json_bool(json, true);
				rephper_json_object_end(json);
				return;
			}

			walk->stack[walk->depth++] = table;
			rephper_json_key(json, "elements");
			rephper_json_array_begin(json);
			ZEND_HASH_FOREACH_KEY_VAL(table, index, key, item) {
				rephper_json_element(json);
				rephper_json_object_begin(json);
				rephper_json_key(json, "key");
				if (key != NULL) {
					rephper_write_string_value(json, ZSTR_VAL(key), ZSTR_LEN(key));
				} else {
					rephper_write_int_value(json, (zend_long) index);
				}
				rephper_json_key(json, "value");
				rephper_write_zval(json, item, walk);
				rephper_json_object_end(json);
			} ZEND_HASH_FOREACH_END();
			rephper_json_array_end(json);
			walk->depth--;
			rephper_json_object_end(json);
			return;
		}
		case IS_CONSTANT_AST:
			rephper_json_object_begin(json);
			rephper_json_key(json, "type");
			rephper_json_cstr(json, "constant_ast");
			rephper_json_key(json, "ast");
			rephper_write_ast(json, Z_ASTVAL_P(value), walk, 0);
			rephper_json_object_end(json);
			return;
		default:
			rephper_json_object_begin(json);
			rephper_json_key(json, "type");
			rephper_json_cstr(json, "unsupported");
			rephper_json_key(json, "zend_type");
			rephper_json_u32(json, (uint32_t) Z_TYPE_P(value));
			rephper_json_object_end(json);
			return;
	}
}

static void rephper_write_type(rephper_json *json, zend_type type, int depth)
{
	zend_string *display;
	const char *kind;

	if (!ZEND_TYPE_IS_SET(type) || depth > 8) {
		rephper_json_null(json);
		return;
	}

	rephper_json_object_begin(json);
	rephper_json_key(json, "display");
	display = zend_type_to_string(type);
	if (display == NULL) {
		rephper_json_null(json);
	} else {
		(void) rephper_json_bytes(json, ZSTR_VAL(display), ZSTR_LEN(display));
		zend_string_release(display);
	}

	rephper_json_key(json, "nullable");
	rephper_json_bool(json, ZEND_TYPE_ALLOW_NULL(type));

	if (ZEND_TYPE_IS_UNION(type)) {
		kind = "union";
	} else if (ZEND_TYPE_IS_INTERSECTION(type)) {
		kind = "intersection";
	} else if (ZEND_TYPE_HAS_NAME(type) || ZEND_TYPE_HAS_LITERAL_NAME(type)) {
		kind = "name";
	} else {
		kind = "builtin";
	}

	rephper_json_key(json, "kind");
	rephper_json_cstr(json, kind);

	if (ZEND_TYPE_HAS_NAME(type) && ZEND_TYPE_NAME(type) != NULL) {
		rephper_json_key(json, "name");
		(void) rephper_json_bytes(json, ZSTR_VAL(ZEND_TYPE_NAME(type)), ZSTR_LEN(ZEND_TYPE_NAME(type)));
	} else if (ZEND_TYPE_HAS_LITERAL_NAME(type) && ZEND_TYPE_LITERAL_NAME(type) != NULL) {
		rephper_json_key(json, "name");
		rephper_json_cstr(json, ZEND_TYPE_LITERAL_NAME(type));
	}

	if (ZEND_TYPE_HAS_LIST(type) && ZEND_TYPE_LIST(type) != NULL) {
		zend_type *member;

		rephper_json_key(json, "members");
		rephper_json_array_begin(json);
		ZEND_TYPE_LIST_FOREACH(ZEND_TYPE_LIST(type), member) {
			rephper_json_element(json);
			rephper_write_type(json, *member, depth + 1);
		} ZEND_TYPE_LIST_FOREACH_END();
		rephper_json_array_end(json);
	}

	rephper_json_object_end(json);
}

static void rephper_write_flags(rephper_json *json, uint32_t flags, const rephper_flag *table, size_t count)
{
	size_t i;

	rephper_json_array_begin(json);
	for (i = 0; i < count; i++) {
		if ((flags & table[i].bit) != 0) {
			rephper_json_element(json);
			rephper_json_cstr(json, table[i].name);
		}
	}
	rephper_json_array_end(json);
}

static const rephper_flag rephper_fn_flags[] = {
	{ZEND_ACC_PUBLIC, "public"},
	{ZEND_ACC_PROTECTED, "protected"},
	{ZEND_ACC_PRIVATE, "private"},
	{ZEND_ACC_STATIC, "static"},
	{ZEND_ACC_FINAL, "final"},
	{ZEND_ACC_ABSTRACT, "abstract"},
	{ZEND_ACC_HAS_TYPE_HINTS, "has_type_hints"},
	{ZEND_ACC_TOP_LEVEL, "top_level"},
	{ZEND_ACC_DEPRECATED, "deprecated"},
	{ZEND_ACC_RETURN_REFERENCE, "return_reference"},
	{ZEND_ACC_HAS_RETURN_TYPE, "has_return_type"},
	{ZEND_ACC_VARIADIC, "variadic"},
	{ZEND_ACC_HAS_FINALLY_BLOCK, "has_finally"},
	{ZEND_ACC_EARLY_BINDING, "early_binding"},
	{ZEND_ACC_USES_THIS, "uses_this"},
	{ZEND_ACC_CTOR, "constructor"},
	{ZEND_ACC_CLOSURE, "closure"},
	{ZEND_ACC_FAKE_CLOSURE, "fake_closure"},
	{ZEND_ACC_GENERATOR, "generator"},
	{ZEND_ACC_OVERRIDE, "override"},
	{ZEND_ACC_STRICT_TYPES, "strict_types"},
};

static const rephper_flag rephper_class_flags[] = {
	{ZEND_ACC_IMPLICIT_ABSTRACT_CLASS, "implicit_abstract"},
	{ZEND_ACC_EXPLICIT_ABSTRACT_CLASS, "abstract"},
	{ZEND_ACC_FINAL, "final"},
	{ZEND_ACC_READONLY_CLASS, "readonly"},
	{ZEND_ACC_ANON_CLASS, "anonymous"},
	{ZEND_ACC_LINKED, "linked"},
	{ZEND_ACC_ALLOW_DYNAMIC_PROPERTIES, "allow_dynamic_properties"},
	{ZEND_ACC_NOT_SERIALIZABLE, "not_serializable"},
};

static const rephper_flag rephper_const_flags[] = {
	{ZEND_ACC_PUBLIC, "public"},
	{ZEND_ACC_PROTECTED, "protected"},
	{ZEND_ACC_PRIVATE, "private"},
	{ZEND_ACC_FINAL, "final"},
	{ZEND_CLASS_CONST_IS_CASE, "enum_case"},
	{ZEND_ACC_DEPRECATED, "deprecated"},
};

static const rephper_flag rephper_property_flags[] = {
	{ZEND_ACC_PUBLIC, "public"},
	{ZEND_ACC_PROTECTED, "protected"},
	{ZEND_ACC_PRIVATE, "private"},
	{ZEND_ACC_STATIC, "static"},
	{ZEND_ACC_READONLY, "readonly"},
	{ZEND_ACC_FINAL, "final"},
	{ZEND_ACC_ABSTRACT, "abstract"},
	{ZEND_ACC_VIRTUAL, "virtual"},
	{ZEND_ACC_PROMOTED, "promoted"},
	{ZEND_ACC_PUBLIC_SET, "public_set"},
	{ZEND_ACC_PROTECTED_SET, "protected_set"},
	{ZEND_ACC_PRIVATE_SET, "private_set"},
};

/* A trait alias stores at most one of these. Zero means the adaptation wrote none. */
static const rephper_flag rephper_trait_alias_flags[] = {
	{ZEND_ACC_PUBLIC, "public"},
	{ZEND_ACC_PROTECTED, "protected"},
	{ZEND_ACC_PRIVATE, "private"},
	{ZEND_ACC_FINAL, "final"},
};

static const char *rephper_class_kind(const zend_class_entry *ce)
{
	if (ce->ce_flags & ZEND_ACC_ENUM) {
		return "enum";
	}
	if (ce->ce_flags & ZEND_ACC_INTERFACE) {
		return "interface";
	}
	if (ce->ce_flags & ZEND_ACC_TRAIT) {
		return "trait";
	}

	return "class";
}

static void rephper_write_attributes(rephper_json *json, HashTable *attributes)
{
	zend_attribute *attribute;
	rephper_walk walk;

	rephper_json_array_begin(json);
	if (attributes == NULL) {
		rephper_json_array_end(json);
		return;
	}

	memset(&walk, 0, sizeof(walk));
	ZEND_HASH_FOREACH_PTR(attributes, attribute) {
		uint32_t i;

		if (attribute == NULL) {
			continue;
		}

		rephper_json_element(json);
		rephper_json_object_begin(json);
		rephper_json_key(json, "name");
		if (attribute->name == NULL) {
			rephper_json_null(json);
		} else {
			(void) rephper_json_bytes(json, ZSTR_VAL(attribute->name), ZSTR_LEN(attribute->name));
		}
		rephper_json_key(json, "line");
		rephper_json_u32(json, attribute->lineno);
		/* 0 is the function or class. Parameter offsets are 1-based. */
		rephper_json_key(json, "offset");
		rephper_json_u32(json, attribute->offset);
		rephper_json_key(json, "arguments");
		rephper_json_array_begin(json);
		for (i = 0; i < attribute->argc; i++) {
			rephper_json_element(json);
			rephper_json_object_begin(json);
			rephper_json_key(json, "name");
			if (attribute->args[i].name == NULL) {
				rephper_json_null(json);
			} else {
				(void) rephper_json_bytes(json, ZSTR_VAL(attribute->args[i].name), ZSTR_LEN(attribute->args[i].name));
			}
			rephper_json_key(json, "value");
			rephper_write_zval(json, &attribute->args[i].value, &walk);
			rephper_json_object_end(json);
		}
		rephper_json_array_end(json);
		rephper_json_object_end(json);
	} ZEND_HASH_FOREACH_END();
	rephper_json_array_end(json);
}

static void rephper_write_zend_string(rephper_json *json, zend_string *value)
{
	if (value == NULL) {
		rephper_json_null(json);
		return;
	}

	(void) rephper_json_bytes(json, ZSTR_VAL(value), ZSTR_LEN(value));
}

/*
 * trait_aliases and trait_precedences are NULL-terminated. trait_names is
 * counted by num_traits and has no sentinel. exclude_class_names is counted
 * by num_excludes. The bytes are copied into the JSON buffer.
 */
static void rephper_write_trait_aliases(rephper_json *json, zend_class_entry *ce)
{
	zend_trait_alias **alias;

	rephper_json_array_begin(json);
	if (ce->trait_aliases == NULL) {
		rephper_json_array_end(json);
		return;
	}

	for (alias = ce->trait_aliases; *alias != NULL; alias++) {
		rephper_json_element(json);
		rephper_json_object_begin(json);
		rephper_json_key(json, "trait");
		rephper_write_zend_string(json, (*alias)->trait_method.class_name);
		rephper_json_key(json, "method");
		rephper_write_zend_string(json, (*alias)->trait_method.method_name);
		rephper_json_key(json, "alias");
		rephper_write_zend_string(json, (*alias)->alias);
		rephper_json_key(json, "modifiers");
		rephper_write_flags(
			json,
			(*alias)->modifiers,
			rephper_trait_alias_flags,
			sizeof(rephper_trait_alias_flags) / sizeof(rephper_trait_alias_flags[0])
		);
		rephper_json_object_end(json);
	}
	rephper_json_array_end(json);
}

static void rephper_write_trait_precedences(rephper_json *json, zend_class_entry *ce)
{
	zend_trait_precedence **precedence;

	rephper_json_array_begin(json);
	if (ce->trait_precedences == NULL) {
		rephper_json_array_end(json);
		return;
	}

	for (precedence = ce->trait_precedences; *precedence != NULL; precedence++) {
		uint32_t i;

		rephper_json_element(json);
		rephper_json_object_begin(json);
		rephper_json_key(json, "trait");
		rephper_write_zend_string(json, (*precedence)->trait_method.class_name);
		rephper_json_key(json, "method");
		rephper_write_zend_string(json, (*precedence)->trait_method.method_name);
		rephper_json_key(json, "instead_of");
		rephper_json_array_begin(json);
		for (i = 0; i < (*precedence)->num_excludes; i++) {
			rephper_json_element(json);
			rephper_write_zend_string(json, (*precedence)->exclude_class_names[i]);
		}
		rephper_json_array_end(json);
		rephper_json_object_end(json);
	}
	rephper_json_array_end(json);
}

static void rephper_write_property_name(rephper_json *json, zend_string *name)
{
	const char *class_name = NULL;
	const char *prop_name = NULL;

	if (name == NULL) {
		rephper_json_null(json);
		return;
	}

	if (zend_unmangle_property_name(name, &class_name, &prop_name) == SUCCESS && prop_name != NULL) {
		(void) rephper_json_bytes(json, prop_name, strlen(prop_name));
		return;
	}

	(void) rephper_json_bytes(json, ZSTR_VAL(name), ZSTR_LEN(name));
}

static void rephper_write_class(rephper_json *json, zend_class_entry *ce)
{
	uint32_t i;
	zend_string *key;
	zend_class_constant *constant;
	zend_property_info *property;
	rephper_walk walk;
	zend_string *parent = NULL;

	memset(&walk, 0, sizeof(walk));
	rephper_json_object_begin(json);
	rephper_json_key(json, "name");
	if (ce->name == NULL) {
		rephper_json_null(json);
	} else {
		(void) rephper_json_bytes(json, ZSTR_VAL(ce->name), ZSTR_LEN(ce->name));
	}
	rephper_json_key(json, "kind");
	rephper_json_cstr(json, rephper_class_kind(ce));
	rephper_json_key(json, "flags");
	rephper_write_flags(json, ce->ce_flags, rephper_class_flags, sizeof(rephper_class_flags) / sizeof(rephper_class_flags[0]));

	if ((ce->ce_flags & ZEND_ACC_LINKED) && ce->parent != NULL) {
		parent = ce->parent->name;
	} else if ((ce->ce_flags & ZEND_ACC_LINKED) == 0) {
		parent = ce->parent_name;
	}

	rephper_json_key(json, "parent");
	if (parent == NULL) {
		rephper_json_null(json);
	} else {
		(void) rephper_json_bytes(json, ZSTR_VAL(parent), ZSTR_LEN(parent));
	}

	rephper_json_key(json, "interfaces");
	rephper_json_array_begin(json);
	if ((ce->ce_flags & ZEND_ACC_LINKED) && ce->interfaces != NULL) {
		for (i = 0; i < ce->num_interfaces; i++) {
			if (ce->interfaces[i] == NULL || ce->interfaces[i]->name == NULL) {
				continue;
			}
			rephper_json_element(json);
			(void) rephper_json_bytes(json, ZSTR_VAL(ce->interfaces[i]->name), ZSTR_LEN(ce->interfaces[i]->name));
		}
	} else if (ce->interface_names != NULL) {
		for (i = 0; i < ce->num_interfaces; i++) {
			if (ce->interface_names[i].name == NULL) {
				continue;
			}
			rephper_json_element(json);
			(void) rephper_json_bytes(json, ZSTR_VAL(ce->interface_names[i].name), ZSTR_LEN(ce->interface_names[i].name));
		}
	}
	rephper_json_array_end(json);

	rephper_json_key(json, "traits");
	rephper_json_array_begin(json);
	if (ce->trait_names != NULL) {
		for (i = 0; i < ce->num_traits; i++) {
			if (ce->trait_names[i].name == NULL) {
				continue;
			}
			rephper_json_element(json);
			(void) rephper_json_bytes(json, ZSTR_VAL(ce->trait_names[i].name), ZSTR_LEN(ce->trait_names[i].name));
		}
	}
	rephper_json_array_end(json);

	rephper_json_key(json, "trait_aliases");
	rephper_write_trait_aliases(json, ce);
	rephper_json_key(json, "trait_precedences");
	rephper_write_trait_precedences(json, ce);

	rephper_json_key(json, "filename");
	if (ce->info.user.filename == NULL) {
		rephper_json_null(json);
	} else {
		(void) rephper_json_bytes(json, ZSTR_VAL(ce->info.user.filename), ZSTR_LEN(ce->info.user.filename));
	}
	rephper_json_key(json, "line_start");
	rephper_json_u32(json, ce->info.user.line_start);
	rephper_json_key(json, "line_end");
	rephper_json_u32(json, ce->info.user.line_end);

	rephper_json_key(json, "enum_backing");
	if ((ce->ce_flags & ZEND_ACC_ENUM) == 0 || ce->enum_backing_type == IS_UNDEF) {
		rephper_json_null(json);
	} else if (ce->enum_backing_type == IS_LONG) {
		rephper_json_cstr(json, "int");
	} else if (ce->enum_backing_type == IS_STRING) {
		rephper_json_cstr(json, "string");
	} else {
		rephper_json_cstr(json, "unknown");
	}

	rephper_json_key(json, "properties");
	rephper_json_array_begin(json);
	ZEND_HASH_FOREACH_PTR(&ce->properties_info, property) {
		const zval *initial = NULL;

		if (property == NULL || property->ce != ce) {
			continue;
		}

		rephper_json_element(json);
		rephper_json_object_begin(json);
		rephper_json_key(json, "name");
		rephper_write_property_name(json, property->name);
		rephper_json_key(json, "flags");
		rephper_write_flags(json, property->flags, rephper_property_flags, sizeof(rephper_property_flags) / sizeof(rephper_property_flags[0]));
		rephper_json_key(json, "type");
		rephper_write_type(json, property->type, 0);
		rephper_json_key(json, "default");
		if ((property->flags & ZEND_ACC_STATIC) && ce->default_static_members_table != NULL
			&& property->offset < (uint32_t) ce->default_static_members_count) {
			initial = &ce->default_static_members_table[property->offset];
		} else if ((property->flags & ZEND_ACC_STATIC) == 0 && ce->default_properties_table != NULL) {
			uint32_t slot = OBJ_PROP_TO_NUM(property->offset);

			if (slot < (uint32_t) ce->default_properties_count) {
				initial = &ce->default_properties_table[slot];
			}
		}
		if (initial == NULL || Z_TYPE_P(initial) == IS_UNDEF) {
			rephper_json_null(json);
		} else {
			rephper_write_zval(json, initial, &walk);
		}
		rephper_json_key(json, "hooks");
		rephper_json_array_begin(json);
		if (property->hooks != NULL) {
			if (property->hooks[ZEND_PROPERTY_HOOK_GET] != NULL) {
				rephper_json_element(json);
				rephper_json_cstr(json, "get");
			}
			if (property->hooks[ZEND_PROPERTY_HOOK_SET] != NULL) {
				rephper_json_element(json);
				rephper_json_cstr(json, "set");
			}
		}
		rephper_json_array_end(json);
		rephper_json_key(json, "attributes");
		rephper_write_attributes(json, property->attributes);
		rephper_json_key(json, "doc_comment");
		rephper_write_zend_string(json, property->doc_comment);
		rephper_json_object_end(json);
	} ZEND_HASH_FOREACH_END();
	rephper_json_array_end(json);

	rephper_json_key(json, "constants");
	rephper_json_array_begin(json);
	ZEND_HASH_FOREACH_STR_KEY_PTR(&ce->constants_table, key, constant) {
		uint32_t constant_flags;

		if (constant == NULL || constant->ce != ce || key == NULL) {
			continue;
		}

		constant_flags = ZEND_CLASS_CONST_FLAGS(constant);
		rephper_json_element(json);
		rephper_json_object_begin(json);
		rephper_json_key(json, "name");
		(void) rephper_json_bytes(json, ZSTR_VAL(key), ZSTR_LEN(key));
		rephper_json_key(json, "flags");
		rephper_write_flags(json, constant_flags, rephper_const_flags, sizeof(rephper_const_flags) / sizeof(rephper_const_flags[0]));
		rephper_json_key(json, "type");
		rephper_write_type(json, constant->type, 0);
		rephper_json_key(json, "value");
		rephper_write_zval(json, &constant->value, &walk);
		rephper_json_key(json, "attributes");
		rephper_write_attributes(json, constant->attributes);
		rephper_json_key(json, "doc_comment");
		rephper_write_zend_string(json, constant->doc_comment);
		rephper_json_object_end(json);
	} ZEND_HASH_FOREACH_END();
	rephper_json_array_end(json);

	rephper_json_key(json, "attributes");
	rephper_write_attributes(json, ce->attributes);
	rephper_json_key(json, "doc_comment");
	rephper_write_zend_string(json, ce->doc_comment);
	rephper_json_object_end(json);
}

static void rephper_capture_classes(const zend_string *filename)
{
	zend_class_entry *ce;

	if (g_classes_captured || filename == NULL) {
		return;
	}

	g_classes_captured = true;
	rephper_json_array_begin(&g_classes);
	ZEND_HASH_FOREACH_PTR(EG(class_table), ce) {
		if (ce == NULL || ce->type != ZEND_USER_CLASS || ce->info.user.filename == NULL) {
			continue;
		}
		if (!zend_string_equals(ce->info.user.filename, filename)) {
			continue;
		}
		rephper_json_element(&g_classes);
		rephper_write_class(&g_classes, ce);
	} ZEND_HASH_FOREACH_END();
	rephper_json_array_end(&g_classes);
	if (g_classes.failed) {
		g_doc.failed = true;
	}
}

static const char *rephper_send_mode(const zend_arg_info *info)
{
	switch (ZEND_ARG_SEND_MODE(info)) {
		case ZEND_SEND_BY_REF:
			return "reference";
		case ZEND_SEND_PREFER_REF:
			return "prefer_reference";
		default:
			return "value";
	}
}

static void rephper_write_operand(
	rephper_json *json,
	const zend_op_array *op_array,
	const zend_op *opline,
	const znode_op *operand,
	uint8_t op_type,
	int position
) {
	uint8_t smart = op_type & (IS_SMART_BRANCH_JMPZ | IS_SMART_BRANCH_JMPNZ);
	uint8_t base = op_type & (IS_CONST | IS_TMP_VAR | IS_VAR | IS_CV);

	rephper_json_object_begin(json);
	rephper_json_key(json, "kind");

	if (rephper_is_jump_operand(opline, position)) {
		rephper_json_cstr(json, "jump");
		rephper_json_key(json, "target");
		rephper_json_u32(json, operand->opline_num);
	} else if (position == 1 && op_type == IS_UNUSED && rephper_unused_op1_means_this(opline->opcode)) {
		rephper_json_cstr(json, "this");
	} else if (opline->opcode == ZEND_FAST_CALL && position == 1) {
		rephper_json_cstr(json, "try_catch");
		rephper_json_key(json, "index");
		rephper_json_u32(json, operand->num);
	} else if ((opline->opcode == ZEND_BRK || opline->opcode == ZEND_CONT) && position == 1) {
		rephper_json_cstr(json, "level");
		rephper_json_key(json, "value");
		rephper_json_u32(json, operand->num);
	} else if (opline->opcode == ZEND_GOTO && position == 1) {
		rephper_json_cstr(json, "label");
		rephper_json_key(json, "id");
		rephper_json_u32(json, operand->num);
	} else {
		switch (base) {
			case IS_CONST:
				rephper_json_cstr(json, "const");
				rephper_json_key(json, "index");
				rephper_json_u32(json, operand->constant);
				if (position == 2 && (opline->opcode == ZEND_SWITCH_LONG
						|| opline->opcode == ZEND_SWITCH_STRING
						|| opline->opcode == ZEND_MATCH)) {
					rephper_json_key(json, "jump_table");
					rephper_json_bool(json, true);
				}
				break;
			case IS_TMP_VAR:
				rephper_json_cstr(json, "tmp");
				rephper_json_key(json, "index");
				rephper_json_u32(json, operand->var);
				break;
			case IS_VAR:
				rephper_json_cstr(json, "var");
				rephper_json_key(json, "index");
				rephper_json_u32(json, operand->var);
				break;
			case IS_CV: {
				uint32_t index;

				rephper_json_cstr(json, "cv");
				rephper_json_key(json, "index");
				if (rephper_cv_index(operand->var, op_array->last_var, &index)) {
					rephper_json_u32(json, index);
					rephper_json_key(json, "name");
					if (op_array->vars == NULL || op_array->vars[index] == NULL) {
						rephper_json_null(json);
					} else {
						(void) rephper_json_bytes(json, ZSTR_VAL(op_array->vars[index]), ZSTR_LEN(op_array->vars[index]));
					}
				} else {
					rephper_json_null(json);
					rephper_json_key(json, "encoded");
					rephper_json_u32(json, operand->var);
				}
				break;
			}
			case IS_UNUSED:
				rephper_json_cstr(json, "unused");
				if (!rephper_num_is_sentinel(operand->num)) {
					rephper_json_key(json, "value");
					rephper_json_u32(json, operand->num);
				}
				break;
			default:
				rephper_json_cstr(json, "unknown");
				rephper_json_key(json, "type");
				rephper_json_u32(json, base);
				rephper_json_key(json, "value");
				rephper_json_u32(json, operand->num);
				break;
		}
	}

	if (smart != 0) {
		rephper_json_key(json, "smart_branch");
		rephper_json_array_begin(json);
		if (smart & IS_SMART_BRANCH_JMPZ) {
			rephper_json_element(json);
			rephper_json_cstr(json, "jmpz");
		}
		if (smart & IS_SMART_BRANCH_JMPNZ) {
			rephper_json_element(json);
			rephper_json_cstr(json, "jmpnz");
		}
		rephper_json_array_end(json);
	}

	rephper_json_object_end(json);
}

static const char *rephper_live_kind(uint32_t kind)
{
	switch (kind) {
		case ZEND_LIVE_TMPVAR:
			return "tmp";
		case ZEND_LIVE_LOOP:
			return "loop";
		case ZEND_LIVE_SILENCE:
			return "silence";
		case ZEND_LIVE_ROPE:
			return "rope";
		case ZEND_LIVE_NEW:
			return "new";
		default:
			return "unknown";
	}
}

static void rephper_write_opline_num(rephper_json *json, uint32_t number)
{
	if (number == (uint32_t) -1) {
		rephper_json_null(json);
		return;
	}

	rephper_json_u32(json, number);
}

static int rephper_seen_id(const zend_op_array *op_array)
{
	uint32_t i;

	for (i = 0; i < g_seen_count; i++) {
		if (g_seen[i].op_array == op_array) {
			return (int) g_seen[i].id;
		}
	}

	return -1;
}

static void rephper_remember(const zend_op_array *op_array, uint32_t id)
{
	rephper_seen_op *grown;

	if (g_seen_count == g_seen_cap) {
		uint32_t cap = g_seen_cap == 0 ? 16 : g_seen_cap * 2;

		grown = realloc(g_seen, cap * sizeof(*g_seen));
		if (grown == NULL) {
			g_doc.failed = true;
			return;
		}
		g_seen = grown;
		g_seen_cap = cap;
	}

	g_seen[g_seen_count].op_array = op_array;
	g_seen[g_seen_count].id = id;
	g_seen_count++;
}

static void rephper_begin_document(void)
{
	rephper_json_object_begin(&g_doc);
	rephper_json_key(&g_doc, "ir_version");
	rephper_json_u32(&g_doc, REPHPER_IR_VERSION);

	rephper_json_key(&g_doc, "php");
	rephper_json_object_begin(&g_doc);
	rephper_json_key(&g_doc, "version");
	rephper_json_cstr(&g_doc, PHP_VERSION);
	rephper_json_key(&g_doc, "version_id");
	rephper_json_u32(&g_doc, PHP_VERSION_ID);
	rephper_json_key(&g_doc, "api");
	rephper_json_u32(&g_doc, PHP_API_VERSION);
	rephper_json_key(&g_doc, "zend_module_api");
	rephper_json_u32(&g_doc, ZEND_MODULE_API_NO);
	rephper_json_key(&g_doc, "zend_extension_api");
	rephper_json_u32(&g_doc, ZEND_EXTENSION_API_NO);
	rephper_json_key(&g_doc, "zts");
#ifdef ZTS
	rephper_json_bool(&g_doc, true);
#else
	rephper_json_bool(&g_doc, false);
#endif
	rephper_json_key(&g_doc, "debug");
	/* ZEND_DEBUG is defined as 0 on a release build, so a bare #ifdef is wrong. */
#if ZEND_DEBUG
	rephper_json_bool(&g_doc, true);
#else
	rephper_json_bool(&g_doc, false);
#endif
	rephper_json_object_end(&g_doc);

	rephper_json_key(&g_doc, "compilation");
	rephper_json_object_begin(&g_doc);
	rephper_json_key(&g_doc, "stage");
	rephper_json_cstr(&g_doc, "pre_pass_two");
	rephper_json_key(&g_doc, "jumps");
	rephper_json_cstr(&g_doc, "absolute_opline");
	rephper_json_object_end(&g_doc);

	rephper_json_key(&g_doc, "op_arrays");
	rephper_json_array_begin(&g_doc);
	g_started = true;
}

static void rephper_write_op_array(const zend_op_array *op_array, uint32_t id)
{
	rephper_json *json = &g_doc;
	rephper_walk walk;
	const char *kind = rephper_kind(op_array);
	bool context = CG(context).op_array == op_array;
	uint32_t i;
	int var;

	memset(&walk, 0, sizeof(walk));
	rephper_json_element(json);
	rephper_json_object_begin(json);
	rephper_json_key(json, "id");
	rephper_json_u32(json, id);
	rephper_json_key(json, "kind");
	rephper_json_cstr(json, kind);
	rephper_json_key(json, "name");
	if (op_array->function_name == NULL) {
		rephper_json_null(json);
	} else {
		(void) rephper_json_bytes(json, ZSTR_VAL(op_array->function_name), ZSTR_LEN(op_array->function_name));
	}
	/*
	 * Omitted for the global namespace so an IR 1 file without this key stays
	 * valid. This is only the namespace still current at pass_two(). Bracketed
	 * blocks clear it before the main script, and several unbracketed
	 * namespaces leave the last name. Document namespaces[] records every region.
	 */
	if (CG(file_context).current_namespace != NULL && ZSTR_LEN(CG(file_context).current_namespace) > 0) {
		rephper_json_key(json, "namespace");
		(void) rephper_json_bytes(
			json,
			ZSTR_VAL(CG(file_context).current_namespace),
			ZSTR_LEN(CG(file_context).current_namespace)
		);
	}
	rephper_json_key(json, "filename");
	if (op_array->filename == NULL) {
		rephper_json_null(json);
	} else {
		(void) rephper_json_bytes(json, ZSTR_VAL(op_array->filename), ZSTR_LEN(op_array->filename));
	}
	rephper_json_key(json, "line_start");
	rephper_json_u32(json, op_array->line_start);
	rephper_json_key(json, "line_end");
	rephper_json_u32(json, op_array->line_end);

	rephper_json_key(json, "scope");
	if (op_array->scope == NULL || op_array->scope->name == NULL) {
		rephper_json_null(json);
	} else {
		rephper_json_object_begin(json);
		rephper_json_key(json, "name");
		(void) rephper_json_bytes(json, ZSTR_VAL(op_array->scope->name), ZSTR_LEN(op_array->scope->name));
		rephper_json_key(json, "kind");
		rephper_json_cstr(json, rephper_class_kind(op_array->scope));
		rephper_json_object_end(json);
	}

	rephper_json_key(json, "property_hook");
	if (strcmp(kind, "property-hook") != 0) {
		rephper_json_null(json);
	} else {
		const zend_property_info *info = rephper_active_property(op_array);

		rephper_json_object_begin(json);
		rephper_json_key(json, "property");
		rephper_write_property_name(json, info == NULL ? NULL : info->name);
		rephper_json_key(json, "hook");
		if (context && CG(context).active_property_hook_kind == ZEND_PROPERTY_HOOK_GET) {
			rephper_json_cstr(json, "get");
		} else if (context && CG(context).active_property_hook_kind == ZEND_PROPERTY_HOOK_SET) {
			rephper_json_cstr(json, "set");
		} else {
			rephper_json_null(json);
		}
		rephper_json_object_end(json);
	}

	rephper_json_key(json, "flags");
	rephper_write_flags(json, op_array->fn_flags, rephper_fn_flags, sizeof(rephper_fn_flags) / sizeof(rephper_fn_flags[0]));
	rephper_json_key(json, "num_args");
	rephper_json_u32(json, op_array->num_args);
	rephper_json_key(json, "required_num_args");
	rephper_json_u32(json, op_array->required_num_args);
	rephper_json_key(json, "return_type");
	if ((op_array->fn_flags & ZEND_ACC_HAS_RETURN_TYPE) && op_array->arg_info != NULL) {
		rephper_write_type(json, (op_array->arg_info - 1)->type, 0);
	} else {
		rephper_json_null(json);
	}

	/*
	 * zend_compile_params() allocates arg_info with safe_emalloc() and never
	 * writes default_value. That field is uninitialized here. A default is the
	 * CONST operand of RECV_INIT, and op1.num is the 1-based argument number.
	 * The variadic parameter is stored after num_args.
	 */
	rephper_json_key(json, "arguments");
	rephper_json_array_begin(json);
	if (op_array->arg_info != NULL) {
		uint32_t argument_count = op_array->num_args;

		if (op_array->fn_flags & ZEND_ACC_VARIADIC) {
			argument_count++;
		}

		for (i = 0; i < argument_count; i++) {
			zend_arg_info *info = &op_array->arg_info[i];
			int default_literal = -1;
			uint32_t opcode_index;

			if (op_array->opcodes != NULL) {
				for (opcode_index = 0; opcode_index < op_array->last; opcode_index++) {
					zend_op *candidate = &op_array->opcodes[opcode_index];

					if (candidate->opcode == ZEND_RECV_INIT
						&& candidate->op1_type == IS_UNUSED
						&& candidate->op1.num == i + 1
						&& (candidate->op2_type & (IS_CONST | IS_TMP_VAR | IS_VAR | IS_CV)) == IS_CONST) {
						default_literal = (int) candidate->op2.constant;
						break;
					}
				}
			}

			rephper_json_element(json);
			rephper_json_object_begin(json);
			rephper_json_key(json, "name");
			if (info->name == NULL) {
				rephper_json_null(json);
			} else {
				(void) rephper_json_bytes(json, ZSTR_VAL(info->name), ZSTR_LEN(info->name));
			}
			rephper_json_key(json, "type");
			rephper_write_type(json, info->type, 0);
			rephper_json_key(json, "send");
			rephper_json_cstr(json, rephper_send_mode(info));
			rephper_json_key(json, "variadic");
			rephper_json_bool(json, ZEND_ARG_IS_VARIADIC(info));
			rephper_json_key(json, "promoted");
			rephper_json_bool(json, ZEND_ARG_IS_PROMOTED(info));
			rephper_json_key(json, "default_literal");
			if (default_literal < 0) {
				rephper_json_null(json);
			} else {
				rephper_json_u32(json, (uint32_t) default_literal);
			}
			rephper_json_object_end(json);
		}
	}
	rephper_json_array_end(json);

	rephper_json_key(json, "variables");
	rephper_json_array_begin(json);
	for (var = 0; var < op_array->last_var; var++) {
		rephper_json_element(json);
		if (op_array->vars == NULL || op_array->vars[var] == NULL) {
			rephper_json_null(json);
		} else {
			(void) rephper_json_bytes(json, ZSTR_VAL(op_array->vars[var]), ZSTR_LEN(op_array->vars[var]));
		}
	}
	rephper_json_array_end(json);

	rephper_json_key(json, "temporary_variables");
	rephper_json_u32(json, op_array->T);
	rephper_json_key(json, "literals");
	rephper_json_array_begin(json);
	if (op_array->literals != NULL) {
		for (var = 0; var < op_array->last_literal; var++) {
			rephper_json_element(json);
			rephper_write_zval(json, &op_array->literals[var], &walk);
		}
	}
	rephper_json_array_end(json);

	rephper_json_key(json, "static_variables");
	if (op_array->static_variables == NULL) {
		rephper_json_null(json);
	} else {
		zend_string *name;
		zval *initial;

		rephper_json_array_begin(json);
		ZEND_HASH_FOREACH_STR_KEY_VAL(op_array->static_variables, name, initial) {
			rephper_json_element(json);
			rephper_json_object_begin(json);
			rephper_json_key(json, "name");
			if (name == NULL) {
				rephper_json_null(json);
			} else {
				(void) rephper_json_bytes(json, ZSTR_VAL(name), ZSTR_LEN(name));
			}
			rephper_json_key(json, "value");
			rephper_write_zval(json, initial, &walk);
			rephper_json_object_end(json);
		} ZEND_HASH_FOREACH_END();
		rephper_json_array_end(json);
	}

	rephper_json_key(json, "try_catch");
	rephper_json_array_begin(json);
	if (op_array->try_catch_array != NULL) {
		for (var = 0; var < op_array->last_try_catch; var++) {
			zend_try_catch_element *element = &op_array->try_catch_array[var];

			rephper_json_element(json);
			rephper_json_object_begin(json);
			rephper_json_key(json, "try");
			rephper_write_opline_num(json, element->try_op);
			rephper_json_key(json, "catch");
			rephper_write_opline_num(json, element->catch_op);
			rephper_json_key(json, "finally");
			rephper_write_opline_num(json, element->finally_op);
			rephper_json_key(json, "finally_end");
			rephper_write_opline_num(json, element->finally_end);
			rephper_json_object_end(json);
		}
	}
	rephper_json_array_end(json);

	rephper_json_key(json, "live_ranges");
	rephper_json_array_begin(json);
	if (op_array->live_range != NULL) {
		for (var = 0; var < op_array->last_live_range; var++) {
			zend_live_range *range = &op_array->live_range[var];

			rephper_json_element(json);
			rephper_json_object_begin(json);
			rephper_json_key(json, "kind");
			rephper_json_cstr(json, rephper_live_kind(range->var & ZEND_LIVE_MASK));
			rephper_json_key(json, "var");
			rephper_json_u32(json, range->var & ~ZEND_LIVE_MASK);
			rephper_json_key(json, "start");
			rephper_json_u32(json, range->start);
			rephper_json_key(json, "end");
			rephper_json_u32(json, range->end);
			rephper_json_object_end(json);
		}
	}
	rephper_json_array_end(json);

	rephper_json_key(json, "breaks");
	if (!context) {
		rephper_json_null(json);
	} else {
		rephper_json_array_begin(json);
		if (CG(context).brk_cont_array != NULL) {
			for (var = 0; var < CG(context).last_brk_cont; var++) {
				zend_brk_cont_element *element = &CG(context).brk_cont_array[var];

				rephper_json_element(json);
				rephper_json_object_begin(json);
				rephper_json_key(json, "start");
				rephper_json_i64(json, element->start);
				rephper_json_key(json, "continue");
				rephper_json_i64(json, element->cont);
				rephper_json_key(json, "break");
				rephper_json_i64(json, element->brk);
				rephper_json_key(json, "parent");
				rephper_json_i64(json, element->parent);
				rephper_json_key(json, "switch");
				rephper_json_bool(json, element->is_switch);
				rephper_json_object_end(json);
			}
		}
		rephper_json_array_end(json);
	}

	rephper_json_key(json, "labels");
	if (!context) {
		rephper_json_null(json);
	} else {
		zend_string *label_name;
		zend_label *label;

		rephper_json_array_begin(json);
		if (CG(context).labels != NULL) {
			ZEND_HASH_FOREACH_STR_KEY_PTR(CG(context).labels, label_name, label) {
				if (label == NULL) {
					continue;
				}
				rephper_json_element(json);
				rephper_json_object_begin(json);
				rephper_json_key(json, "name");
				if (label_name == NULL) {
					rephper_json_null(json);
				} else {
					(void) rephper_json_bytes(json, ZSTR_VAL(label_name), ZSTR_LEN(label_name));
				}
				rephper_json_key(json, "opline");
				rephper_json_u32(json, label->opline_num);
				rephper_json_key(json, "break_continue");
				rephper_json_i64(json, label->brk_cont);
				rephper_json_object_end(json);
			} ZEND_HASH_FOREACH_END();
		}
		rephper_json_array_end(json);
	}

	rephper_json_key(json, "dynamic_functions");
	rephper_json_array_begin(json);
	if (op_array->dynamic_func_defs != NULL) {
		for (i = 0; i < op_array->num_dynamic_func_defs; i++) {
			int nested = rephper_seen_id(op_array->dynamic_func_defs[i]);

			rephper_json_element(json);
			if (nested < 0) {
				rephper_json_null(json);
			} else {
				rephper_json_u32(json, (uint32_t) nested);
			}
		}
	}
	rephper_json_array_end(json);

	rephper_json_key(json, "attributes");
	rephper_write_attributes(json, op_array->attributes);
	rephper_json_key(json, "doc_comment");
	if (op_array->doc_comment == NULL) {
		rephper_json_null(json);
	} else {
		(void) rephper_json_bytes(json, ZSTR_VAL(op_array->doc_comment), ZSTR_LEN(op_array->doc_comment));
	}

	rephper_json_key(json, "instructions");
	rephper_json_array_begin(json);
	if (op_array->opcodes != NULL) {
		for (i = 0; i < op_array->last; i++) {
			zend_op *opline = &op_array->opcodes[i];

			rephper_json_element(json);
			rephper_json_object_begin(json);
			rephper_json_key(json, "index");
			rephper_json_u32(json, i);
			rephper_json_key(json, "opcode");
			rephper_json_cstr(json, rephper_opcode_name(opline->opcode));
			rephper_json_key(json, "opcode_number");
			rephper_json_u32(json, opline->opcode);
			rephper_json_key(json, "line");
			rephper_json_u32(json, opline->lineno);
			rephper_json_key(json, "op1");
			rephper_write_operand(json, op_array, opline, &opline->op1, opline->op1_type, 1);
			rephper_json_key(json, "op2");
			rephper_write_operand(json, op_array, opline, &opline->op2, opline->op2_type, 2);
			rephper_json_key(json, "result");
			rephper_write_operand(json, op_array, opline, &opline->result, opline->result_type, 0);
			rephper_json_key(json, "extended_value");
			rephper_json_u32(json, opline->extended_value);
			if (rephper_extended_is_jump(opline->opcode)) {
				rephper_json_key(json, "jump_target");
				rephper_json_u32(json, opline->extended_value);
			}
			rephper_json_object_end(json);
		}
	}
	rephper_json_array_end(json);
	rephper_json_object_end(json);
}

void rephper_export_op_array(zend_op_array *op_array)
{
	uint32_t id;

	if (op_array == NULL || !ZEND_USER_CODE(op_array->type) || !rephper_export_enabled() || g_doc.failed) {
		return;
	}

	if (!g_started) {
		rephper_begin_document();
	}

	id = g_next_id++;
	rephper_remember(op_array, id);
	rephper_write_op_array(op_array, id);
	if (strcmp(rephper_kind(op_array), "main") == 0) {
		rephper_capture_classes(op_array->filename);
	}
}

static int rephper_write_file(const char *path, const char *data, size_t length)
{
	struct stat info;
	char temporary[PATH_MAX];
	int written;
	int fd;
	size_t offset = 0;

	if (lstat(path, &info) == 0) {
		if (!S_ISREG(info.st_mode)) {
			fprintf(stderr, "Rephper: IR path is not a regular file: %s\n", path);
			return -1;
		}
	} else if (errno != ENOENT) {
		fprintf(stderr, "Rephper: cannot stat IR path %s: %s\n", path, strerror(errno));
		return -1;
	}

	written = snprintf(temporary, sizeof(temporary), "%s.rephper.%ld.tmp", path, (long) getpid());
	if (written < 0 || (size_t) written >= sizeof(temporary)) {
		fprintf(stderr, "Rephper: IR path is too long\n");
		return -1;
	}

	fd = open(temporary, O_WRONLY | O_CREAT | O_EXCL | O_NOFOLLOW, 0644);
	if (fd < 0) {
		fprintf(stderr, "Rephper: cannot create IR file %s: %s\n", temporary, strerror(errno));
		return -1;
	}

	while (offset < length) {
		ssize_t chunk = write(fd, data + offset, length - offset);

		if (chunk < 0) {
			if (errno == EINTR) {
				continue;
			}
			fprintf(stderr, "Rephper: cannot write IR file %s: %s\n", temporary, strerror(errno));
			close(fd);
			unlink(temporary);
			return -1;
		}
		offset += (size_t) chunk;
	}

	if (close(fd) != 0) {
		fprintf(stderr, "Rephper: cannot close IR file %s: %s\n", temporary, strerror(errno));
		unlink(temporary);
		return -1;
	}

	if (rename(temporary, path) != 0) {
		fprintf(stderr, "Rephper: cannot replace IR file %s: %s\n", path, strerror(errno));
		unlink(temporary);
		return -1;
	}

	return 0;
}

static char *rephper_copy_chars(const char *value, size_t length)
{
	char *copy = malloc(length + 1);

	if (copy == NULL) {
		g_doc.failed = true;
		return NULL;
	}
	memcpy(copy, value, length);
	copy[length] = '\0';

	return copy;
}

static void rephper_note_line(rephper_region *region, uint32_t line)
{
	if (region == NULL || line == 0) {
		return;
	}
	if (line < region->line_start) {
		region->line_start = line;
	}
	if (line > region->line_end) {
		region->line_end = line;
	}
}

static int rephper_u32_cmp(const void *left, const void *right)
{
	uint32_t a = *(const uint32_t *) left;
	uint32_t b = *(const uint32_t *) right;

	return (a > b) - (a < b);
}

static void rephper_lines_push(rephper_lines *lines, uint32_t line)
{
	uint32_t *grown;

	if (line == 0 || g_doc.failed) {
		return;
	}
	if (lines->count == lines->cap) {
		uint32_t cap = lines->cap == 0 ? 8 : lines->cap * 2;

		grown = realloc(lines->lines, cap * sizeof(*grown));
		if (grown == NULL) {
			g_doc.failed = true;
			return;
		}
		lines->lines = grown;
		lines->cap = cap;
	}
	lines->lines[lines->count++] = line;
}

static void rephper_lines_unique(rephper_lines *lines)
{
	uint32_t i;
	uint32_t kept;

	if (lines->count < 2) {
		return;
	}
	qsort(lines->lines, lines->count, sizeof(uint32_t), rephper_u32_cmp);
	kept = 1;
	for (i = 1; i < lines->count; i++) {
		if (lines->lines[i] != lines->lines[kept - 1]) {
			lines->lines[kept++] = lines->lines[i];
		}
	}
	lines->count = kept;
}

static uint32_t rephper_begin_region(const char *name, size_t length, uint32_t line)
{
	rephper_region *grown;
	rephper_region *region;

	if (g_region_count == g_region_cap) {
		uint32_t cap = g_region_cap == 0 ? 4 : g_region_cap * 2;

		grown = realloc(g_regions, cap * sizeof(*g_regions));
		if (grown == NULL) {
			g_doc.failed = true;
			return UINT32_MAX;
		}
		g_regions = grown;
		g_region_cap = cap;
	}
	region = &g_regions[g_region_count];
	memset(region, 0, sizeof(*region));
	region->line_start = line == 0 ? 1 : line;
	region->line_end = region->line_start;
	if (name != NULL && length > 0) {
		region->name = rephper_copy_chars(name, length);
		if (region->name == NULL) {
			return UINT32_MAX;
		}
	}
	g_region_count++;

	return g_region_count - 1;
}

static void rephper_add_function(uint32_t index, zend_string *name, uint32_t start, uint32_t end)
{
	rephper_region *region;
	char *copy;
	char **grown;

	if (index >= g_region_count || name == NULL) {
		g_doc.failed = true;
		return;
	}
	region = &g_regions[index];
	copy = rephper_copy_chars(ZSTR_VAL(name), ZSTR_LEN(name));
	if (copy == NULL) {
		return;
	}
	if (region->function_count == region->function_cap) {
		uint32_t cap = region->function_cap == 0 ? 4 : region->function_cap * 2;

		grown = realloc(region->functions, cap * sizeof(*grown));
		if (grown == NULL) {
			free(copy);
			g_doc.failed = true;
			return;
		}
		region->functions = grown;
		region->function_cap = cap;
	}
	region->functions[region->function_count++] = copy;
	rephper_note_line(region, start);
	rephper_note_line(region, end);
}

static void rephper_collect_lines(zend_ast *ast, rephper_lines *lines, int depth)
{
	uint32_t i;

	if (ast == NULL || depth > 64 || g_doc.failed) {
		return;
	}
	rephper_lines_push(lines, zend_ast_get_lineno(ast));
	if (ast->kind == ZEND_AST_CLOSURE || ast->kind == ZEND_AST_ARROW_FUNC
		|| ast->kind == ZEND_AST_FUNC_DECL || ast->kind == ZEND_AST_METHOD
		|| ast->kind == ZEND_AST_CLASS || ast->kind == ZEND_AST_PROPERTY_HOOK) {
		return;
	}
	if (zend_ast_is_special(ast)) {
		return;
	}
	if (zend_ast_is_list(ast)) {
		zend_ast_list *list = zend_ast_get_list(ast);

		for (i = 0; i < list->children; i++) {
			rephper_collect_lines(list->child[i], lines, depth + 1);
		}
		return;
	}
	for (i = 0; i < zend_ast_get_num_children(ast); i++) {
		rephper_collect_lines(ast->child[i], lines, depth + 1);
	}
}

static void rephper_add_statement(uint32_t index, zend_ast *stmt)
{
	rephper_region *region;
	rephper_lines *grown;
	rephper_lines *statement;

	if (index >= g_region_count || stmt == NULL) {
		g_doc.failed = true;
		return;
	}
	region = &g_regions[index];
	if (region->statement_count == region->statement_cap) {
		uint32_t cap = region->statement_cap == 0 ? 4 : region->statement_cap * 2;

		grown = realloc(region->statements, cap * sizeof(*grown));
		if (grown == NULL) {
			g_doc.failed = true;
			return;
		}
		region->statements = grown;
		region->statement_cap = cap;
	}
	statement = &region->statements[region->statement_count];
	memset(statement, 0, sizeof(*statement));
	rephper_collect_lines(stmt, statement, 0);
	rephper_lines_unique(statement);
	if (statement->count == 0) {
		rephper_lines_push(statement, zend_ast_get_lineno(stmt));
	}
	if (g_doc.failed) {
		free(statement->lines);
		return;
	}
	region->statement_count++;
	if (statement->count > 0) {
		rephper_note_line(region, statement->lines[0]);
		rephper_note_line(region, statement->lines[statement->count - 1]);
	}
}

static uint32_t rephper_walk_stmt(zend_ast *stmt, uint32_t open);

static void rephper_walk_list(zend_ast *ast, uint32_t *open)
{
	uint32_t i;
	zend_ast_list *list;

	if (ast == NULL || g_doc.failed || open == NULL) {
		return;
	}
	if (ast->kind != ZEND_AST_STMT_LIST) {
		*open = rephper_walk_stmt(ast, *open);
		return;
	}
	list = zend_ast_get_list(ast);
	for (i = 0; i < list->children; i++) {
		*open = rephper_walk_stmt(list->child[i], *open);
		if (g_doc.failed) {
			return;
		}
	}
}

static uint32_t rephper_walk_stmt(zend_ast *stmt, uint32_t open)
{
	if (stmt == NULL || g_doc.failed) {
		return open;
	}
	if (stmt->kind == ZEND_AST_STMT_LIST) {
		rephper_walk_list(stmt, &open);
		return open;
	}
	if (stmt->kind == ZEND_AST_NAMESPACE) {
		zend_ast *name_ast = stmt->child[0];
		zend_ast *body = stmt->child[1];
		const char *name = NULL;
		size_t length = 0;
		uint32_t region;

		if (name_ast != NULL) {
			zend_string *ns = zend_ast_get_str(name_ast);

			name = ZSTR_VAL(ns);
			length = ZSTR_LEN(ns);
		}
		region = rephper_begin_region(name, length, zend_ast_get_lineno(stmt));
		if (region == UINT32_MAX) {
			return UINT32_MAX;
		}
		if (body != NULL) {
			rephper_walk_list(body, &region);
			return UINT32_MAX;
		}

		return region;
	}
	if (open == UINT32_MAX) {
		return UINT32_MAX;
	}
	if (stmt->kind == ZEND_AST_FUNC_DECL) {
		zend_ast_decl *decl = (zend_ast_decl *) stmt;

		rephper_add_function(open, decl->name, decl->start_lineno, decl->end_lineno);
		return open;
	}
	if (stmt->kind == ZEND_AST_CLASS || stmt->kind == ZEND_AST_USE
		|| stmt->kind == ZEND_AST_GROUP_USE || stmt->kind == ZEND_AST_HALT_COMPILER
		|| stmt->kind == ZEND_AST_DECLARE) {
		rephper_note_line(&g_regions[open], zend_ast_get_lineno(stmt));
		if (stmt->kind == ZEND_AST_CLASS) {
			rephper_note_line(&g_regions[open], ((zend_ast_decl *) stmt)->end_lineno);
		}
		return open;
	}
	rephper_add_statement(open, stmt);

	return open;
}

static void rephper_write_namespaces(rephper_json *json)
{
	uint32_t i;
	uint32_t j;
	uint32_t k;

	if (g_region_count == 0) {
		return;
	}
	rephper_json_key(json, "namespaces");
	rephper_json_array_begin(json);
	for (i = 0; i < g_region_count; i++) {
		rephper_region *region = &g_regions[i];

		rephper_json_element(json);
		rephper_json_object_begin(json);
		rephper_json_key(json, "name");
		if (region->name == NULL) {
			rephper_json_null(json);
		} else {
			(void) rephper_json_bytes(json, region->name, strlen(region->name));
		}
		rephper_json_key(json, "line_start");
		rephper_json_u32(json, region->line_start);
		rephper_json_key(json, "line_end");
		rephper_json_u32(json, region->line_end);
		rephper_json_key(json, "functions");
		rephper_json_array_begin(json);
		for (j = 0; j < region->function_count; j++) {
			rephper_json_element(json);
			(void) rephper_json_bytes(json, region->functions[j], strlen(region->functions[j]));
		}
		rephper_json_array_end(json);
		rephper_json_key(json, "statements");
		rephper_json_array_begin(json);
		for (j = 0; j < region->statement_count; j++) {
			rephper_json_element(json);
			rephper_json_array_begin(json);
			for (k = 0; k < region->statements[j].count; k++) {
				rephper_json_element(json);
				rephper_json_u32(json, region->statements[j].lines[k]);
			}
			rephper_json_array_end(json);
		}
		rephper_json_array_end(json);
		rephper_json_object_end(json);
	}
	rephper_json_array_end(json);
}

void rephper_export_namespaces(zend_ast *ast)
{
	uint32_t open = UINT32_MAX;

	if (ast == NULL || !rephper_export_enabled() || g_doc.failed) {
		return;
	}
	rephper_walk_list(ast, &open);
}

void rephper_export_finish(void)
{
	char *path;

	rephper_export_ensure();
	if (!rephper_export_enabled() || !g_started) {
		rephper_export_reset();
		return;
	}

	if (!g_doc.failed) {
		rephper_json_array_end(&g_doc);
		rephper_write_namespaces(&g_doc);
		rephper_json_key(&g_doc, "classes");
		if (g_classes_captured && g_classes.data != NULL) {
			rephper_json_splice(&g_doc, g_classes.data, g_classes.length);
		} else {
			rephper_json_array_begin(&g_doc);
			rephper_json_array_end(&g_doc);
		}
		rephper_json_object_end(&g_doc);
		rephper_json_splice(&g_doc, "\n", 1);
	}

	path = g_path;
	g_path = NULL;
	if (path != NULL && !g_doc.failed && g_doc.data != NULL) {
		(void) rephper_write_file(path, g_doc.data, g_doc.length);
	} else if (g_doc.failed) {
		fprintf(stderr, "Rephper: IR export failed\n");
	}

	free(path);
	rephper_export_reset();
}
