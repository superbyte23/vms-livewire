#!/usr/bin/env bash
# Auto-start the VMS server at WSL boot (invoked by cron @reboot).
# Waits for MySQL (127.0.0.1:3307) to accept connections, then runs start-server.sh.
set -u

cd /home/vms/vms-livewire

for _ in $(seq 1 30); do
    if (exec 3<>/dev/tcp/127.0.0.1/3307) 2>/dev/null; then
        exec 3>&- 3<&-
        break
    fi
    sleep 2
done

exec bash /home/vms/vms-livewire/start-server.sh >> /home/vms/vms-livewire/storage/logs/auto-start.log 2>&1