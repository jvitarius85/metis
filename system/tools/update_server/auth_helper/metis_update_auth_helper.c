#define _GNU_SOURCE
#include <ctype.h>
#include <errno.h>
#include <security/pam_appl.h>
#include <pwd.h>
#include <stdbool.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/types.h>
#include <unistd.h>

struct helper_context {
    const char *password;
};

static int helper_conversation(int num_msg, const struct pam_message **msg, struct pam_response **resp, void *appdata_ptr) {
    struct helper_context *context = (struct helper_context *) appdata_ptr;
    struct pam_response *responses = calloc((size_t) num_msg, sizeof(struct pam_response));
    int index = 0;

    if (responses == NULL) {
        return PAM_CONV_ERR;
    }

    for (index = 0; index < num_msg; index++) {
        responses[index].resp_retcode = 0;
        responses[index].resp = NULL;

        if (msg[index] == NULL) {
            free(responses);
            return PAM_CONV_ERR;
        }

        switch (msg[index]->msg_style) {
            case PAM_PROMPT_ECHO_OFF:
            case PAM_PROMPT_ECHO_ON:
                responses[index].resp = strdup(context->password != NULL ? context->password : "");
                if (responses[index].resp == NULL) {
                    free(responses);
                    return PAM_CONV_ERR;
                }
                break;
            case PAM_ERROR_MSG:
            case PAM_TEXT_INFO:
                break;
            default:
                free(responses);
                return PAM_CONV_ERR;
        }
    }

    *resp = responses;
    return PAM_SUCCESS;
}

static bool username_is_safe(const char *value) {
    size_t index = 0;
    size_t length = 0;

    if (value == NULL) {
        return false;
    }

    length = strlen(value);
    if (length == 0U || length > 190U) {
        return false;
    }

    for (index = 0; index < length; index++) {
        const unsigned char c = (unsigned char) value[index];
        if (!(isalnum(c) || c == '_' || c == '-' || c == '.')) {
            return false;
        }
    }

    return true;
}

static char *read_password_stdin(void) {
    size_t capacity = 256U;
    size_t length = 0U;
    int ch = 0;
    char *buffer = malloc(capacity);

    if (buffer == NULL) {
        return NULL;
    }

    while ((ch = fgetc(stdin)) != EOF) {
        if (ch == '\n') {
            break;
        }

        if (length + 1U >= capacity) {
            char *expanded = NULL;
            capacity *= 2U;
            expanded = realloc(buffer, capacity);
            if (expanded == NULL) {
                memset(buffer, 0, length);
                free(buffer);
                return NULL;
            }
            buffer = expanded;
        }

        buffer[length++] = (char) ch;
    }

    buffer[length] = '\0';
    return buffer;
}

static void emit_json(bool ok, const char *username, const char *message) {
    printf(
        "{\"ok\":%s,\"username\":\"%s\",\"message\":\"%s\"}\n",
        ok ? "true" : "false",
        username != NULL ? username : "",
        message != NULL ? message : ""
    );
}

int main(int argc, char **argv) {
    const char *username = NULL;
    const char *service = "login";
    char *password = NULL;
    struct pam_conv conversation;
    struct helper_context context;
    pam_handle_t *pamh = NULL;
    int result = PAM_SYSTEM_ERR;
    int account = PAM_SYSTEM_ERR;
    int index = 0;

    for (index = 1; index < argc; index++) {
        if (strncmp(argv[index], "--username=", 11) == 0) {
            username = argv[index] + 11;
        } else if (strncmp(argv[index], "--service=", 10) == 0) {
            service = argv[index] + 10;
        }
    }

    if (!username_is_safe(username) || service == NULL || service[0] == '\0') {
        emit_json(false, username != NULL ? username : "", "Invalid authentication payload.");
        return 2;
    }

    password = read_password_stdin();
    if (password == NULL) {
        emit_json(false, username, "Unable to read password.");
        return 3;
    }

    if (getpwnam(username) == NULL) {
        memset(password, 0, strlen(password));
        free(password);
        emit_json(false, username, "Authentication failed.");
        return 4;
    }

    context.password = password;
    conversation.conv = helper_conversation;
    conversation.appdata_ptr = &context;

    result = pam_start(service, username, &conversation, &pamh);
    if (result == PAM_SUCCESS) {
        result = pam_authenticate(pamh, 0);
    }
    if (result == PAM_SUCCESS) {
        account = pam_acct_mgmt(pamh, 0);
        if (account != PAM_SUCCESS) {
            result = account;
        }
    }

    if (pamh != NULL) {
        pam_end(pamh, result);
    }

    memset(password, 0, strlen(password));
    free(password);

    if (result == PAM_SUCCESS) {
        emit_json(true, username, "Authentication succeeded.");
        return 0;
    }

    emit_json(false, username, "Authentication failed.");
    return 1;
}
