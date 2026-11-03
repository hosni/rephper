#ifndef REPHPER_PHP84_OPCODES_H
#define REPHPER_PHP84_OPCODES_H

/*
 * Include after php.h and zend_compile.h.
 *
 * These classifiers describe operands at the start of pass_two(), which is
 * when op_array_handler runs. inspect.c and export.c both use them so the
 * text dump and the IR agree.
 */

static inline const char *rephper_opcode_name(uint8_t opcode)
{
	const char *name;

	switch (opcode) {
		case ZEND_GOTO:
			return "GOTO";
		case ZEND_BRK:
			return "BRK";
		case ZEND_CONT:
			return "CONT";
		default:
			break;
	}

	if (opcode > ZEND_VM_LAST_OPCODE) {
		return "UNKNOWN";
	}

	name = zend_get_opcode_name(opcode);
	if (name == NULL || name[0] == '\0') {
		return "UNKNOWN";
	}

	/* zend_get_opcode_name() returns the C constant, including the ZEND_ prefix. */
	if (strncmp(name, "ZEND_", 5) == 0) {
		name += 5;
	}

	return name;
}

static inline bool rephper_num_is_sentinel(uint32_t num)
{
	/* The compiler stores -1 in unused operand fields. 0 is the same empty state. */
	return num == 0 || num == (uint32_t) -1;
}

static inline bool rephper_unused_op1_means_this(uint8_t opcode)
{
	switch (opcode) {
		case ZEND_ASSIGN_OBJ:
		case ZEND_ASSIGN_OBJ_OP:
		case ZEND_ASSIGN_OBJ_REF:
		case ZEND_UNSET_OBJ:
		case ZEND_FETCH_OBJ_R:
		case ZEND_FETCH_OBJ_W:
		case ZEND_FETCH_OBJ_RW:
		case ZEND_FETCH_OBJ_IS:
		case ZEND_FETCH_OBJ_FUNC_ARG:
		case ZEND_FETCH_OBJ_UNSET:
		case ZEND_PRE_INC_OBJ:
		case ZEND_PRE_DEC_OBJ:
		case ZEND_POST_INC_OBJ:
		case ZEND_POST_DEC_OBJ:
		case ZEND_ISSET_ISEMPTY_PROP_OBJ:
			return true;
		default:
			return false;
	}
}

static inline bool rephper_is_jump_operand(const zend_op *opline, int position)
{
	switch (opline->opcode) {
		case ZEND_JMP:
			return position == 1;
		case ZEND_JMPZ:
		case ZEND_JMPNZ:
		case ZEND_JMPZ_EX:
		case ZEND_JMPNZ_EX:
		case ZEND_JMP_SET:
		case ZEND_COALESCE:
		case ZEND_FE_RESET_R:
		case ZEND_FE_RESET_RW:
		case ZEND_JMP_NULL:
		case ZEND_BIND_INIT_STATIC_OR_JMP:
		case ZEND_JMP_FRAMELESS:
		case ZEND_ASSERT_CHECK:
			return position == 2;
		case ZEND_CATCH:
			return position == 2 && (opline->extended_value & ZEND_LAST_CATCH) == 0;
		default:
			return false;
	}
}

static inline bool rephper_extended_is_jump(uint8_t opcode)
{
	switch (opcode) {
		case ZEND_FE_FETCH_R:
		case ZEND_FE_FETCH_RW:
		case ZEND_SWITCH_LONG:
		case ZEND_SWITCH_STRING:
		case ZEND_MATCH:
			return true;
		default:
			return false;
	}
}

static inline bool rephper_cv_index(uint32_t encoded, int last_var, uint32_t *index)
{
	uint32_t decoded;

	if (last_var <= 0) {
		return false;
	}

	decoded = EX_VAR_TO_NUM(encoded);
	if (decoded >= (uint32_t) last_var) {
		return false;
	}

	*index = decoded;
	return true;
}

static inline bool rephper_name_is_property_hook(const zend_string *name)
{
	const char *text;
	size_t length;
	size_t i;

	if (name == NULL || ZSTR_LEN(name) < 5 || ZSTR_VAL(name)[0] != '$') {
		return false;
	}

	text = ZSTR_VAL(name);
	length = ZSTR_LEN(name);
	for (i = 1; i + 1 < length; i++) {
		if (text[i] == ':' && text[i + 1] == ':') {
			return true;
		}
	}

	return false;
}

static inline const zend_property_info *rephper_active_property(const zend_op_array *op_array)
{
	if (op_array->prop_info != NULL) {
		return op_array->prop_info;
	}

	if (CG(context).op_array == op_array) {
		return CG(context).active_property_info;
	}

	return NULL;
}

static inline const char *rephper_kind(const zend_op_array *op_array)
{
	if (op_array->type == ZEND_EVAL_CODE) {
		return "eval";
	}
	if (op_array->prop_info != NULL || rephper_active_property(op_array) != NULL
		|| rephper_name_is_property_hook(op_array->function_name)) {
		return "property-hook";
	}
	if (op_array->fn_flags & ZEND_ACC_CLOSURE) {
		return "closure";
	}
	if (op_array->scope != NULL) {
		return "method";
	}
	if (op_array->function_name != NULL) {
		return "function";
	}

	return "main";
}

#endif
