#include "json.h"

#include <stdlib.h>
#include <string.h>
#include <stdio.h>

void rephper_json_init(rephper_json *json)
{
	json->data = NULL;
	json->length = 0;
	json->capacity = 0;
	json->depth = -1;
	json->failed = false;
	memset(json->first, 0, sizeof(json->first));
}

void rephper_json_free(rephper_json *json)
{
	free(json->data);
	rephper_json_init(json);
}

void rephper_json_reset(rephper_json *json)
{
	json->length = 0;
	json->depth = -1;
	json->failed = false;
	memset(json->first, 0, sizeof(json->first));
}

static void rephper_json_need(rephper_json *json, size_t extra)
{
	size_t required;
	size_t capacity;
	char *data;

	if (json->failed) {
		return;
	}

	if (extra > SIZE_MAX - json->length - 1) {
		json->failed = true;
		return;
	}

	required = json->length + extra + 1;
	if (required <= json->capacity) {
		return;
	}

	capacity = json->capacity == 0 ? 4096 : json->capacity;
	while (capacity < required) {
		if (capacity > SIZE_MAX / 2) {
			json->failed = true;
			return;
		}
		capacity *= 2;
	}

	data = realloc(json->data, capacity);
	if (data == NULL) {
		json->failed = true;
		return;
	}

	json->data = data;
	json->capacity = capacity;
}

static void rephper_json_raw(rephper_json *json, const char *text, size_t length)
{
	if (json->failed) {
		return;
	}

	rephper_json_need(json, length);
	if (json->failed) {
		return;
	}

	memcpy(json->data + json->length, text, length);
	json->length += length;
	json->data[json->length] = '\0';
}

static void rephper_json_cstr_raw(rephper_json *json, const char *text)
{
	rephper_json_raw(json, text, strlen(text));
}

static void rephper_json_indent(rephper_json *json)
{
	int spaces;
	int i;

	spaces = (json->depth + 1) * 2;
	for (i = 0; i < spaces; i++) {
		rephper_json_raw(json, " ", 1);
	}
}

static void rephper_json_break(rephper_json *json)
{
	if (json->depth < 0 || json->depth >= (int) (sizeof(json->first) / sizeof(json->first[0]))) {
		json->failed = true;
		return;
	}

	if (!json->first[json->depth]) {
		rephper_json_raw(json, ",", 1);
	} else {
		json->first[json->depth] = false;
	}

	rephper_json_raw(json, "\n", 1);
	rephper_json_indent(json);
}

static void rephper_json_begin(rephper_json *json, char open)
{
	char text[1];

	json->depth++;
	if (json->depth < 0 || json->depth >= (int) (sizeof(json->first) / sizeof(json->first[0]))) {
		json->failed = true;
		return;
	}

	json->first[json->depth] = true;
	text[0] = open;
	rephper_json_raw(json, text, 1);
}

static void rephper_json_end(rephper_json *json, char close)
{
	bool empty;
	char text[1];

	if (json->depth < 0 || json->depth >= (int) (sizeof(json->first) / sizeof(json->first[0]))) {
		json->failed = true;
		return;
	}

	empty = json->first[json->depth];
	json->depth--;
	if (!empty) {
		rephper_json_raw(json, "\n", 1);
		rephper_json_indent(json);
	}

	text[0] = close;
	rephper_json_raw(json, text, 1);
}

void rephper_json_object_begin(rephper_json *json)
{
	rephper_json_begin(json, '{');
}

void rephper_json_object_end(rephper_json *json)
{
	rephper_json_end(json, '}');
}

void rephper_json_array_begin(rephper_json *json)
{
	rephper_json_begin(json, '[');
}

void rephper_json_array_end(rephper_json *json)
{
	rephper_json_end(json, ']');
}

void rephper_json_key(rephper_json *json, const char *key)
{
	rephper_json_break(json);
	rephper_json_raw(json, "\"", 1);
	rephper_json_cstr_raw(json, key);
	rephper_json_raw(json, "\": ", 3);
}

void rephper_json_element(rephper_json *json)
{
	rephper_json_break(json);
}

void rephper_json_splice(rephper_json *json, const char *text, size_t length)
{
	rephper_json_raw(json, text, length);
}

void rephper_json_null(rephper_json *json)
{
	rephper_json_cstr_raw(json, "null");
}

void rephper_json_bool(rephper_json *json, bool value)
{
	rephper_json_cstr_raw(json, value ? "true" : "false");
}

void rephper_json_u32(rephper_json *json, uint32_t value)
{
	char buffer[16];
	int length;

	length = snprintf(buffer, sizeof(buffer), "%u", value);
	if (length < 0) {
		json->failed = true;
		return;
	}

	rephper_json_raw(json, buffer, (size_t) length);
}

void rephper_json_i64(rephper_json *json, int64_t value)
{
	char buffer[32];
	int length;

	length = snprintf(buffer, sizeof(buffer), "%lld", (long long) value);
	if (length < 0) {
		json->failed = true;
		return;
	}

	rephper_json_raw(json, buffer, (size_t) length);
}

void rephper_json_cstr(rephper_json *json, const char *value)
{
	if (value == NULL) {
		rephper_json_null(json);
		return;
	}

	(void) rephper_json_bytes(json, value, strlen(value));
}

static size_t rephper_utf8_length(const unsigned char *text, size_t available)
{
	unsigned char lead;
	size_t need;
	size_t i;
	unsigned int code;

	if (available == 0) {
		return 0;
	}

	lead = text[0];
	if (lead <= 0x7f) {
		return 1;
	}
	if ((lead & 0xe0) == 0xc0) {
		need = 2;
		code = lead & 0x1f;
	} else if ((lead & 0xf0) == 0xe0) {
		need = 3;
		code = lead & 0x0f;
	} else if ((lead & 0xf8) == 0xf0) {
		need = 4;
		code = lead & 0x07;
	} else {
		return 0;
	}

	if (available < need) {
		return 0;
	}

	for (i = 1; i < need; i++) {
		if ((text[i] & 0xc0) != 0x80) {
			return 0;
		}
		code = (code << 6) | (text[i] & 0x3f);
	}

	if (need == 2 && code < 0x80) {
		return 0;
	}
	if (need == 3 && code < 0x800) {
		return 0;
	}
	if (need == 4 && code < 0x10000) {
		return 0;
	}
	if (code > 0x10ffff || (code >= 0xd800 && code <= 0xdfff)) {
		return 0;
	}

	return need;
}

static void rephper_json_hex4(rephper_json *json, unsigned int value)
{
	char buffer[7];
	int length;

	length = snprintf(buffer, sizeof(buffer), "\\u%04x", value & 0xffff);
	if (length < 0) {
		json->failed = true;
		return;
	}

	rephper_json_raw(json, buffer, (size_t) length);
}

static void rephper_json_escape_byte(rephper_json *json, unsigned char byte)
{
	switch (byte) {
		case '"':
			rephper_json_cstr_raw(json, "\\\"");
			return;
		case '\\':
			rephper_json_cstr_raw(json, "\\\\");
			return;
		case '\b':
			rephper_json_cstr_raw(json, "\\b");
			return;
		case '\f':
			rephper_json_cstr_raw(json, "\\f");
			return;
		case '\n':
			rephper_json_cstr_raw(json, "\\n");
			return;
		case '\r':
			rephper_json_cstr_raw(json, "\\r");
			return;
		case '\t':
			rephper_json_cstr_raw(json, "\\t");
			return;
		default:
			break;
	}

	if (byte < 0x20) {
		rephper_json_hex4(json, byte);
		return;
	}

	rephper_json_raw(json, (const char *) &byte, 1);
}

bool rephper_json_bytes(rephper_json *json, const char *value, size_t length)
{
	const unsigned char *text = (const unsigned char *) value;
	size_t i = 0;
	bool binary = false;

	if (value == NULL) {
		rephper_json_null(json);
		return false;
	}

	while (i < length) {
		size_t sequence = rephper_utf8_length(text + i, length - i);

		if (sequence == 0) {
			binary = true;
			break;
		}
		i += sequence;
	}

	rephper_json_raw(json, "\"", 1);
	if (binary) {
		for (i = 0; i < length; i++) {
			rephper_json_hex4(json, text[i]);
		}
	} else {
		i = 0;
		while (i < length) {
			size_t sequence = rephper_utf8_length(text + i, length - i);
			size_t n;

			if (sequence == 1) {
				rephper_json_escape_byte(json, text[i]);
			} else {
				rephper_json_raw(json, (const char *) text + i, sequence);
			}
			for (n = 0; n < sequence; n++) {
				i++;
			}
		}
	}
	rephper_json_raw(json, "\"", 1);

	return binary;
}
