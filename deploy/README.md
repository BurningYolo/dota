# Ubuntu/Debian VPS deployment

The collector runs as a `systemd` service and stores its persistent database and match files in `/var/lib/dota-observer/data`. The web frontend can be served separately; it is not required for collecting matches.

## Requirements

Install PHP CLI with cURL and SQLite support:

```sh
sudo apt update
sudo apt install -y php-cli php-curl php-sqlite3
```

## Install and resume

Copy the project tree to a temporary directory on the VPS, including its local `data/` folder. From that directory, run:

```sh
sudo bash deploy/install-vps.sh
```

The installer copies the app to `/opt/dota-observer`, imports local collector data only when the VPS has no database yet, installs the systemd unit, and starts the service. It never overwrites an existing VPS database. Edit `/etc/dota-observer/collector.env` if you need different collection settings. Keep any real API key only in that server-side file.

## Resume the existing collection

For an existing VPS database, stop the service before copying data. Copy the local `data/` directory to `/var/lib/dota-observer/data` on the VPS, preserving `collector.sqlite`, `live/`, and `final/`. Then set ownership and start the service:

```sh
sudo systemctl stop dota-collector
sudo chown -R dota-collector:dota-collector /var/lib/dota-observer
sudo systemctl start dota-collector
```

If this is a first install, copy data before the first service start. The unit creates the state directory automatically.

## Check and control it

```sh
sudo systemctl status dota-collector
sudo journalctl -u dota-collector -f
sudo systemctl restart dota-collector
sudo systemctl stop dota-collector
```

On reboot, systemd starts the collector again and it resumes from the SQLite state. The service does not make any extra API calls beyond those made by `script.php`.
