#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  exec sudo "$0" "$@"
fi

source_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
app_dir=/opt/dota-observer
state_dir=/var/lib/dota-observer
config_dir=/etc/dota-observer
service_name=dota-collector

if ! id -u dota-collector >/dev/null 2>&1; then
  useradd --system --no-create-home --shell /usr/sbin/nologin dota-collector
fi

install -d -o root -g root -m 0755 "$app_dir" "$app_dir/public" "$config_dir"
install -d -o dota-collector -g dota-collector -m 0750 "$state_dir" "$state_dir/data"

for file in script.php status.php cleanup.php README.md; do
  if [[ -f "$source_dir/$file" ]]; then
    install -m 0644 "$source_dir/$file" "$app_dir/$file"
  fi
done
cp -a "$source_dir/public/." "$app_dir/public/"

# Import the local collection only on a fresh VPS; never overwrite VPS state.
if [[ ! -f "$state_dir/data/collector.sqlite" && -d "$source_dir/data" ]]; then
  cp -a "$source_dir/data/." "$state_dir/data/"
  chown -R dota-collector:dota-collector "$state_dir/data"
fi

install -m 0644 "$source_dir/deploy/dota-collector.service" "/etc/systemd/system/${service_name}.service"
if [[ ! -f "$config_dir/collector.env" ]]; then
  install -m 0600 "$source_dir/deploy/collector.env.example" "$config_dir/collector.env"
fi

systemctl daemon-reload
systemctl enable "$service_name"
systemctl restart "$service_name"
systemctl --no-pager --full status "$service_name"
