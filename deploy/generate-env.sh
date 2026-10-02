#!/usr/bin/env bash
#
# Fills the generated secrets in deploy/.env, creating it from deploy/.env.example on first run,
# then reports what a human still has to supply. It asks nothing and is safe to re-run: by default
# it only writes keys that are currently empty.
#
# Usage (from the repository root):
#   deploy/generate-env.sh

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
env_example="$script_dir/.env.example"
env_file="$script_dir/.env"

get_env() {
	sed -n "s/^$1=//p" "$env_file" | head -n 1
}

set_env() {
	local key="$1" value="$2" escaped
	escaped=$(printf '%s' "$value" | sed -e 's/\\/\\\\/g' -e 's/\//\\\//g' -e 's/&/\\&/g')
	if grep -q "^${key}=" "$env_file"; then
		sed -i.bak "s/^${key}=.*/${key}=${escaped}/" "$env_file"
		rm -f "${env_file}.bak"
	else
		printf '%s=%s\n' "$key" "$value" >>"$env_file"
	fi
}

generate_uuid() {
	if command -v uuidgen >/dev/null 2>&1; then
		uuidgen | tr '[:upper:]' '[:lower:]'
		return
	fi
	local hex variant
	hex=$(openssl rand -hex 16)
	variant=$(printf '%x' $(((16#${hex:16:1} & 3) | 8)))
	printf '%s-%s-4%s-%s%s-%s\n' "${hex:0:8}" "${hex:8:4}" "${hex:13:3}" "$variant" "${hex:17:3}" "${hex:20:12}"
}

# Writes $2 to $1 unless the key already has a value.
fill() {
	local key="$1" value="$2"
	if [ -n "$(get_env "$key")" ]; then
		echo "  $key: already set, kept"
		return
	fi
	set_env "$key" "$value"
	echo "  $key: written"
}

if ! command -v openssl >/dev/null 2>&1; then
	echo "error: openssl is required to generate secrets, please install it." >&2
	exit 1
fi

if [ ! -e "$env_file" ]; then
	echo "Creating \"$env_file\" from $(basename "$env_example")..."
	cp "$env_example" "$env_file"
fi

echo
echo "Generating secrets in \"$env_file\"..."
fill APP_KEY "base64:$(openssl rand -base64 32)"
fill DB_PASSWORD "$(openssl rand -hex 32)"
fill REVERB_APP_ID "$(generate_uuid)"
fill REVERB_APP_SECRET "$(openssl rand -hex 32)"
fill BACKUP_ENCRYPTION_PASSPHRASE "$(openssl rand -hex 32)"

# REVERB_APP_KEY is deliberately absent: the image supplies it, baked from the same value
# as the browser-side key in the JS bundle. Writing it here would override that and break
# the websocket handshake.

missing=()
for key in CONTACT_EMAIL EVE_CLIENT_ID EVE_CLIENT_SECRET; do
	if [ -z "$(get_env "$key")" ]; then
		missing+=("$key")
	fi
done

echo
if [ ${#missing[@]} -gt 0 ]; then
	echo "Still to fill in by hand in \"$env_file\":" >&2
	printf '  - %s\n' "${missing[@]}" >&2
else
	echo "Done — \"$env_file\" is complete."
fi
