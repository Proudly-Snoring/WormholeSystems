#!/usr/bin/env bash
#
# One-shot database backup: dumps the application database, then gzips it and encrypts it with OpenSSL
# (AES-256-CBC, key derived from the passphrase with PBKDF2).
#
# Meant to be triggered on a schedule from outside the stack, not run continuously —
# cf. the "Backups" section of deploy/readme.md for how to schedule it and how to restore.

set -euo pipefail

for binary in openssl gzip; do
	if ! command -v "$binary" >/dev/null 2>&1; then
		echo "error: $binary is required to write the backup, please install it in the backup image." >&2
		exit 1
	fi
done

retention_days=14
backup_dir=/backups
# Follows DB_DATABASE, which docker-compose.yml lets deploy/.env override.
database="${MARIADB_DATABASE:-wormholesystems}"
timestamp="$(date -u +%Y-%m-%dT%H-%M-%SZ)"
dest="$backup_dir/$database-$timestamp.sql.gz.enc"
tmp="$dest.tmp"

# Password and passphrase go through process substitution rather than CLI flags, so neither ends up visible to other processes via `ps`.
mariadb-dump --defaults-extra-file=<(printf '[client]\npassword=%s\n' "$MARIADB_ROOT_PASSWORD") --host="$MARIADB_HOST" --user=root --single-transaction "$database" \
	| gzip \
	| openssl enc -aes-256-cbc -pbkdf2 -salt -pass file:<(printf '%s' "$BACKUP_ENCRYPTION_PASSPHRASE") -out "$tmp"

# Renamed into place only once the dump succeeded, so a failed run can never overwrite or get pruned in place of a good backup.
mv "$tmp" "$dest"
echo "Backup written to $dest"

# Cleanup old files (including temporary files left by a failed run).
find "$backup_dir" -name "$database-*.sql.gz.enc*" -mtime "+$retention_days" -delete
