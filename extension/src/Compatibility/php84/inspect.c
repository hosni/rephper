#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "zend_compile.h"
#include "zend_vm_opcodes.h"

#include "php_rephper.h"
#include "opcodes.h"

#if PHP_API_VERSION != 20240924
# error src/Compatibility/php84 implements PHP 8.4 (API 20240924) only
#endif

#ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
#endif

/*
 * op_array_handler runs at the start of pass_two(), before Zend rewrites
 * operands into their runtime form:
 *
 * - IS_CV values are already EX_NUM_TO_VAR() indexes. lookup_cv() stores
 *   them that way, and pass_two() does not touch IS_CV. zend_get_compiled_variable_name()
 *   decodes that encoding, so it is the right API here.
 * - IS_TMP_VAR and IS_VAR are still raw compiler numbers. pass_two() later
 *   turns them into EX_NUM_TO_VAR(last_var + number). Do not run those
 *   numbers through zend_get_compiled_variable_name().
 * - IS_CONST is still an index into op_array->literals. Use CT_CONSTANT_EX()
 *   with this op array. The CT_CONSTANT() macro reads CG(active_op_array)
 *   and is the wrong table once a nested op array is being finished.
 * - Jump operands still hold absolute opline numbers. pass_two() later
 *   converts them to pointers or relative offsets.
 * - ZEND_BRK, ZEND_CONT, and ZEND_GOTO are pseudo-opcodes (253-255).
 *   pass_two() rewrites BRK/CONT to ZEND_JMP and resolves GOTO labels.
 *   ZEND_FAST_CALL still holds a try/catch index, not a jump target.
 * - Property-hook op arrays are named "$prop::get" or "$prop::set" before
 *   pass_two(). op_array->prop_info is assigned only after pass_two()
 *   returns, so the live property is CG(context).active_property_info.
 */

static void rephper_print_escaped(const char *text, size_t length)
{
	size_t i;

	if (text == NULL) {
		fputs("-", stderr);
		return;
	}

	for (i = 0; i < length; i++) {
		unsigned char c = (unsigned char) text[i];

		switch (c) {
			case '\\':
				fputs("\\\\", stderr);
				break;
			case '"':
				fputs("\\\"", stderr);
				break;
			case '\n':
				fputs("\\n", stderr);
				break;
			case '\r':
				fputs("\\r", stderr);
				break;
			case '\t':
				fputs("\\t", stderr);
				break;
			case '\0':
				fputs("\\0", stderr);
				break;
			default:
				if (c < 0x20 || c == 0x7f) {
					fprintf(stderr, "\\x%02x", c);
				} else {
					fputc(c, stderr);
				}
				break;
		}
	}
}

static void rephper_print_zstr(const zend_string *str)
{
	if (str == NULL) {
		fputs("-", stderr);
		return;
	}

	rephper_print_escaped(ZSTR_VAL(str), ZSTR_LEN(str));
}

static void rephper_print_zval(const zval *zv, int depth);
static void rephper_print_var_name(const zend_string *name);

static void rephper_print_array(const HashTable *ht, int depth)
{
	zend_ulong index;
	zend_string *key;
	zval *value;
	int count = 0;
	uint32_t total;

	if (ht == NULL) {
		fputs("0", stderr);
		return;
	}

	total = zend_hash_num_elements(ht);
	fprintf(stderr, "%u", total);
	if (total == 0 || depth <= 0) {
		return;
	}

	fputs(": [", stderr);
	ZEND_HASH_FOREACH_KEY_VAL((HashTable *) ht, index, key, value) {
		if (count >= 16) {
			fputs(", ...", stderr);
			break;
		}
		if (count > 0) {
			fputs(", ", stderr);
		}
		if (key != NULL) {
			fputc('"', stderr);
			rephper_print_zstr(key);
			fputs("\"=>", stderr);
		} else {
			fprintf(stderr, ZEND_ULONG_FMT "=>", index);
		}
		rephper_print_zval(value, depth - 1);
		count++;
	} ZEND_HASH_FOREACH_END();
	fputc(']', stderr);
}

static void rephper_print_zval(const zval *zv, int depth)
{
	if (zv == NULL) {
		fputs("null", stderr);
		return;
	}

	switch (Z_TYPE_P(zv)) {
		case IS_UNDEF:
			fputs("undef", stderr);
			break;
		case IS_NULL:
			fputs("null", stderr);
			break;
		case IS_FALSE:
			fputs("false", stderr);
			break;
		case IS_TRUE:
			fputs("true", stderr);
			break;
		case IS_LONG:
			fprintf(stderr, "int(" ZEND_LONG_FMT ")", Z_LVAL_P(zv));
			break;
		case IS_DOUBLE:
			fprintf(stderr, "float(%.17g)", Z_DVAL_P(zv));
			break;
		case IS_STRING:
			fputs("string(\"", stderr);
			rephper_print_escaped(Z_STRVAL_P(zv), Z_STRLEN_P(zv));
			fputs("\")", stderr);
			break;
		case IS_ARRAY:
			fputs("array(", stderr);
			rephper_print_array(Z_ARRVAL_P(zv), depth);
			fputc(')', stderr);
			break;
		case IS_CONSTANT_AST:
			fputs("constant_ast", stderr);
			break;
		default:
			fprintf(stderr, "zval_type(%u)", (unsigned) Z_TYPE_P(zv));
			break;
	}
}

static void rephper_print_type(zend_type type)
{
	zend_string *name;

	if (!ZEND_TYPE_IS_SET(type)) {
		fputs("-", stderr);
		return;
	}

	name = zend_type_to_string(type);
	if (name == NULL) {
		fputs("-", stderr);
		return;
	}

	rephper_print_zstr(name);
	zend_string_release(name);
}

static void rephper_print_cv(const zend_op_array *op_array, uint32_t encoded)
{
	uint32_t index;
	zend_string *name;

	if (!rephper_cv_index(encoded, op_array->last_var, &index) || op_array->vars == NULL) {
		fprintf(stderr, "CV(?:%u)", encoded);
		return;
	}

	name = zend_get_compiled_variable_name(op_array, encoded);
	fprintf(stderr, "CV(%u:", index);
	rephper_print_var_name(name);
	fputc(')', stderr);
}

static void rephper_print_var_name(const zend_string *name)
{
	if (name == NULL) {
		fputs("-", stderr);
		return;
	}

	/* Compiled variable and parameter names are stored without the sigil. */
	if (ZSTR_LEN(name) == 0 || ZSTR_VAL(name)[0] != '$') {
		fputc('$', stderr);
	}
	rephper_print_zstr(name);
}

static void rephper_print_operand(
	const zend_op_array *op_array,
	const zend_op *opline,
	const znode_op *operand,
	uint8_t op_type,
	int position
) {
	uint8_t smart = op_type & (IS_SMART_BRANCH_JMPZ | IS_SMART_BRANCH_JMPNZ);
	uint8_t base = op_type & (IS_CONST | IS_TMP_VAR | IS_VAR | IS_CV);

	if (rephper_is_jump_operand(opline, position)) {
		fprintf(stderr, "JMP(%u)", operand->opline_num);
		return;
	}

	/*
	 * For $this->prop, the compiler sets op1_type to IS_UNUSED and copies
	 * an uninitialized znode. The number is stack garbage and changes
	 * between processes. The operand means $this.
	 */
	if (position == 1 && op_type == IS_UNUSED && rephper_unused_op1_means_this(opline->opcode)) {
		fputs("THIS", stderr);
		return;
	}

	if (opline->opcode == ZEND_FAST_CALL && position == 1) {
		fprintf(stderr, "TRY_CATCH(%u)", operand->num);
		return;
	}

	if ((opline->opcode == ZEND_BRK || opline->opcode == ZEND_CONT) && position == 1) {
		fprintf(stderr, "LEVEL(%u)", operand->num);
		return;
	}

	if (opline->opcode == ZEND_GOTO && position == 1) {
		fprintf(stderr, "LABEL(%u)", operand->num);
		return;
	}

	switch (base) {
		case IS_CONST: {
			zval *value;

			if (op_array->literals == NULL || op_array->last_literal < 0
				|| operand->constant >= (uint32_t) op_array->last_literal) {
				fprintf(stderr, "CONST(?:%u)", operand->constant);
				break;
			}

			value = CT_CONSTANT_EX(op_array, operand->constant);
			fputs("CONST(", stderr);
			rephper_print_zval(value, 2);
			fputc(')', stderr);
			break;
		}
		case IS_TMP_VAR:
			fprintf(stderr, "TMP(%u)", operand->var);
			break;
		case IS_VAR:
			fprintf(stderr, "VAR(%u)", operand->var);
			break;
		case IS_CV:
			rephper_print_cv(op_array, operand->var);
			break;
		case IS_UNUSED:
		default:
			if (base == IS_UNUSED) {
				if (rephper_num_is_sentinel(operand->num)) {
					fputs("UNUSED", stderr);
				} else {
					fprintf(stderr, "UNUSED(%u)", operand->num);
				}
			} else {
				fprintf(stderr, "TYPE(%u:%u)", (unsigned) base, operand->num);
			}
			break;
	}

	if (smart & IS_SMART_BRANCH_JMPZ) {
		fputs("|SMART_JMPZ", stderr);
	}
	if (smart & IS_SMART_BRANCH_JMPNZ) {
		fputs("|SMART_JMPNZ", stderr);
	}
}

static void rephper_print_extended(const zend_op *opline)
{
	switch (opline->opcode) {
		case ZEND_FE_FETCH_R:
		case ZEND_FE_FETCH_RW:
		case ZEND_SWITCH_LONG:
		case ZEND_SWITCH_STRING:
		case ZEND_MATCH:
			fprintf(stderr, "jmp:%u", opline->extended_value);
			return;
		default:
			break;
	}

	if (rephper_num_is_sentinel(opline->extended_value)) {
		fputs("-", stderr);
		return;
	}

	fprintf(stderr, "%u", opline->extended_value);
}

static void rephper_print_property(const zend_op_array *op_array)
{
	const zend_property_info *info = rephper_active_property(op_array);
	const char *class_name = NULL;
	const char *prop_name = NULL;

	if (info == NULL || info->name == NULL) {
		fputs("-", stderr);
		return;
	}

	if (zend_unmangle_property_name(info->name, &class_name, &prop_name) == SUCCESS && prop_name != NULL) {
		rephper_print_escaped(prop_name, strlen(prop_name));
		return;
	}

	rephper_print_zstr(info->name);
}

static void rephper_print_hook(const zend_op_array *op_array)
{
	zend_property_hook_kind kind;

	if (strcmp(rephper_kind(op_array), "property-hook") != 0) {
		fputs("-", stderr);
		return;
	}

	if (CG(context).op_array == op_array) {
		kind = CG(context).active_property_hook_kind;
		if (kind == ZEND_PROPERTY_HOOK_GET) {
			fputs("get", stderr);
			return;
		}
		if (kind == ZEND_PROPERTY_HOOK_SET) {
			fputs("set", stderr);
			return;
		}
	}

	fputs("-", stderr);
}

static void rephper_print_variables(const zend_op_array *op_array)
{
	int i;

	fputs("Compiled variables:\n", stderr);
	if (op_array->last_var <= 0 || op_array->vars == NULL) {
		fputs("(none)\n", stderr);
		return;
	}

	for (i = 0; i < op_array->last_var; i++) {
		fprintf(stderr, "%d ", i);
		rephper_print_var_name(op_array->vars[i]);
		fputc('\n', stderr);
	}
}

static void rephper_print_literals(const zend_op_array *op_array)
{
	int i;

	fputs("Literals:\n", stderr);
	if (op_array->last_literal <= 0 || op_array->literals == NULL) {
		fputs("(none)\n", stderr);
		return;
	}

	for (i = 0; i < op_array->last_literal; i++) {
		fprintf(stderr, "%d ", i);
		rephper_print_zval(&op_array->literals[i], 2);
		fputc('\n', stderr);
	}
}

static void rephper_print_arguments(const zend_op_array *op_array)
{
	uint32_t i;

	fputs("Argument details:\n", stderr);
	if (op_array->num_args == 0 || op_array->arg_info == NULL) {
		fputs("(none)\n", stderr);
		return;
	}

	for (i = 0; i < op_array->num_args; i++) {
		zend_arg_info *info = &op_array->arg_info[i];

		fprintf(stderr, "%u ", i);
		rephper_print_var_name(info->name);
		fputc(' ', stderr);
		rephper_print_type(info->type);
		fputc('\n', stderr);
	}
}

static void rephper_print_opcodes(const zend_op_array *op_array)
{
	uint32_t i;

	fputs("Opcodes:\n", stderr);
	if (op_array->last == 0 || op_array->opcodes == NULL) {
		fputs("(none)\n", stderr);
		return;
	}

	for (i = 0; i < op_array->last; i++) {
		zend_op *opline = &op_array->opcodes[i];

		fprintf(stderr, "%u %s line=%u op1=", i, rephper_opcode_name(opline->opcode), opline->lineno);
		rephper_print_operand(op_array, opline, &opline->op1, opline->op1_type, 1);
		fputs(" op2=", stderr);
		rephper_print_operand(op_array, opline, &opline->op2, opline->op2_type, 2);
		fputs(" result=", stderr);
		rephper_print_operand(op_array, opline, &opline->result, opline->result_type, 0);
		fputs(" ext=", stderr);
		rephper_print_extended(opline);
		fputc('\n', stderr);
	}
}

void rephper_inspect_op_array(zend_op_array *op_array)
{
#ifdef ZTS
	ZEND_TSRMLS_CACHE_UPDATE();
#endif

	if (op_array == NULL || !ZEND_USER_CODE(op_array->type)) {
		return;
	}

	fputs("Rephper: Compiled op array\n", stderr);
	fputs("File: ", stderr);
	rephper_print_zstr(op_array->filename);
	fputc('\n', stderr);

	fputs("Function: ", stderr);
	if (op_array->function_name == NULL) {
		fputs("<main>", stderr);
	} else {
		rephper_print_zstr(op_array->function_name);
	}
	fputc('\n', stderr);

	fputs("Class: ", stderr);
	if (op_array->scope == NULL || op_array->scope->name == NULL) {
		fputs("-", stderr);
	} else {
		rephper_print_zstr(op_array->scope->name);
	}
	fputc('\n', stderr);

	fprintf(stderr, "Kind: %s\n", rephper_kind(op_array));

	fputs("Property: ", stderr);
	rephper_print_property(op_array);
	fputc('\n', stderr);

	fputs("Hook: ", stderr);
	rephper_print_hook(op_array);
	fputc('\n', stderr);

	fprintf(stderr, "Opcodes: %u\n", op_array->last);
	fprintf(stderr, "Arguments: %u\n", op_array->num_args);
	fprintf(stderr, "Required arguments: %u\n", op_array->required_num_args);
	fprintf(stderr, "Compiled variables: %d\n", op_array->last_var);
	fprintf(stderr, "Temporary variables: %u\n", op_array->T);
	fprintf(stderr, "Literals: %d\n", op_array->last_literal);

	fputs("Return type: ", stderr);
	if ((op_array->fn_flags & ZEND_ACC_HAS_RETURN_TYPE) && op_array->arg_info != NULL) {
		rephper_print_type((op_array->arg_info - 1)->type);
	} else {
		fputs("-", stderr);
	}
	fputc('\n', stderr);

	fprintf(stderr, "Lines: %u-%u\n\n", op_array->line_start, op_array->line_end);

	rephper_print_variables(op_array);
	fputc('\n', stderr);
	rephper_print_literals(op_array);
	fputc('\n', stderr);
	rephper_print_arguments(op_array);
	fputc('\n', stderr);
	rephper_print_opcodes(op_array);
	fputc('\n', stderr);
}
