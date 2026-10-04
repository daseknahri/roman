#!/bin/bash
# Start the one-time/idempotent site setup in the background, then hand over to the official entrypoint
# (which copies WordPress core into the volume and writes wp-config.php before Apache starts).
set -e
/usr/local/bin/site-init.sh > /proc/1/fd/1 2>&1 &
exec docker-entrypoint.sh "$@"
