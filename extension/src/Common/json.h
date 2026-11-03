#ifndef REPHPER_JSON_H
#define REPHPER_JSON_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

/*
 * Small JSON writer. Values are appended in call order, so the output does
 * not depend on hash iteration of the writer itself. Callers own key order.
 */

typedef struct rephper_json {
	char *data;
	size_t length;
	size_t capacity;
	int depth;
	bool first[48];
	bool failed;
} rephper_json;

void rephper_json_init(rephper_json *json);
void rephper_json_free(rephper_json *json);
void rephper_json_reset(rephper_json *json);

void rephper_json_object_begin(rephper_json *json);
void rephper_json_object_end(rephper_json *json);
void rephper_json_array_begin(rephper_json *json);
void rephper_json_array_end(rephper_json *json);
void rephper_json_key(rephper_json *json, const char *key);
void rephper_json_element(rephper_json *json);

void rephper_json_splice(rephper_json *json, const char *text, size_t length);
void rephper_json_null(rephper_json *json);
void rephper_json_bool(rephper_json *json, bool value);
void rephper_json_u32(rephper_json *json, uint32_t value);
void rephper_json_i64(rephper_json *json, int64_t value);
void rephper_json_cstr(rephper_json *json, const char *value);

/*
 * Writes a JSON string. Returns true when the bytes are not valid UTF-8.
 * In that case every byte is encoded as a \\u00XX code unit so the original
 * bytes can be recovered from the low 8 bits.
 */
bool rephper_json_bytes(rephper_json *json, const char *value, size_t length);

#endif
