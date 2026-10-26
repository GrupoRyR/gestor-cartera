#!/usr/bin/env bash
#
# Refuses a production .env with unsafe values (audit S-30). deploy/deploy.sh
# runs it before touching anything. It only reads the file:
#
#   bash deploy/check-env.sh [path/to/.env]     (default: .env)
#
# Values are read as phpdotenv reads them: the first definition of a variable
# wins, quotes and a trailing " # comment" are dropped, and a blank value is
# an empty string. Laravel's env() returns that empty string, not the config
# default, so a blank SESSION_SECURE_COOKIE turns the secure cookie off.
#
# phpdotenv never overrides a variable the process already has, so the same
# names set in this shell's environment (~/.bashrc, the cron line) are
# refused too: the .env could not fix them. Apache's SetEnv is not visible
# from here; docs/arquitectura.md §16 says not to use it for these.

set -euo pipefail

ENV_FILE="${1:-.env}"

if [ ! -f "$ENV_FILE" ]; then
    printf 'ERROR: %s does not exist.\n' "$ENV_FILE" >&2
    exit 1
fi

# Prints the value of the variable $1; fails when the file does not define it.
env_value() {
    local line value

    line="$(grep -m 1 -E "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENV_FILE")" || return 1
    line="${line%$'\r'}"
    value="${line#*=}"
    value="${value#"${value%%[![:space:]]*}"}"

    case "$value" in
        \"*) value="${value#\"}" && value="${value%%\"*}" ;;
        \'*) value="${value#\'}" && value="${value%%\'*}" ;;
        *) value="${value%%[[:space:]]#*}" && value="${value%"${value##*[![:space:]]}"}" ;;
    esac

    printf '%s' "$value"
}

# Lowercase without ${value,,}, which needs bash 4.
lower() {
    printf '%s' "$1" | tr '[:upper:]' '[:lower:]'
}

problems=()

for name in APP_ENV APP_DEBUG APP_URL SESSION_SECURE_COOKIE SESSION_ENCRYPT DEMO_LOGIN; do
    if [ -n "${!name+set}" ]; then
        problems+=("$name is set in the server environment, which wins over .env: unset it.")
    fi
done

if value="$(env_value APP_ENV)" && [ "$value" != 'production' ]; then
    problems+=("APP_ENV is \"$value\": it must be production or left out.")
fi

# APP_DEBUG shows stack traces; DEMO_LOGIN opens /dev/entrar (audit S-11).
for name in APP_DEBUG DEMO_LOGIN; do
    if value="$(env_value "$name")"; then
        case "$(lower "$value")" in
            '' | false | '(false)' | 0) ;;
            *) problems+=("$name is \"$value\": it must be false or left out.") ;;
        esac
    fi
done

if ! value="$(env_value APP_URL)"; then
    problems+=('APP_URL is missing: it must be the https:// address of the app.')
elif [[ "$value" != https://* ]]; then
    problems+=("APP_URL is \"$value\": it must start with https://.")
fi

for name in SESSION_SECURE_COOKIE SESSION_ENCRYPT; do
    if value="$(env_value "$name")"; then
        case "$(lower "$value")" in
            true | '(true)' | 1) ;;
            *) problems+=("$name is \"$value\": it must be true or left out (blank counts as false).") ;;
        esac
    fi
done

if [ "${#problems[@]}" -gt 0 ]; then
    printf 'ERROR: %s has unsafe production values:\n' "$ENV_FILE" >&2
    printf '  - %s\n' "${problems[@]}" >&2
    exit 1
fi

printf '%s passes the production checks.\n' "$ENV_FILE"
