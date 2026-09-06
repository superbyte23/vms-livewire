#!/usr/bin/env bash
set -ue
cd /home/vms/vms-livewire

stop_one() {
  local pidfile="$1"
  [ -f "$pidfile" ] || return 0
  local pid
  pid=$(cat "$pidfile")
  if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
    kill -9 "$pid" 2>/dev/null || true
  fi
  rm -f "$pidfile"
}

stop_one /tmp/vms-franken.pid
stop_one /tmp/vms-queue.pid
sleep 1

nohup frankenphp run --config Frankenphpfile --adapter caddyfile > storage/logs/frankenphp.log 2>&1 &
echo $! > /tmp/vms-franken.pid
nohup php artisan queue:work --tries=1 > storage/logs/queue.log 2>&1 &
echo $! > /tmp/vms-queue.pid

echo "started: frankenphp pid=$(cat /tmp/vms-franken.pid), queue pid=$(cat /tmp/vms-queue.pid)"