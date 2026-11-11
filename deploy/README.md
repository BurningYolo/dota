# Ubuntu/Debian VPS deployment

The collector runs as a `systemd` service and stores its persistent database and match files in `/var/lib/dota-observer/data`. The installer also serves the frontend through Nginx and PHP-FPM on loopback only, so you can view it through an SSH tunnel without exposing the dashboard publicly.

## Requirements

Install PHP CLI with cURL and SQLite support:

```sh
sudo apt update
sudo apt install -y nginx php-cli php-curl php-fpm php-sqlite3
```

## Install and resume

Copy the project tree to a temporary directory on the VPS, including its local `data/` folder. From that directory, run:

```sh
sudo bash deploy/install-vps.sh
```

The installer copies the app to `/opt/dota-observer`, imports local collector data only when the VPS has no database yet, installs the systemd collector and web services, and starts them. It never overwrites an existing VPS database. Edit `/etc/dota-observer/collector.env` if you need different collection settings. Keep any real API key only in that server-side file.

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

## Open the UI over SSH

On your Windows PC, keep this PowerShell command running:

```powershell
ssh -i "$env:USERPROFILE\.ssh\id_ed25519" -N -L 8080:127.0.0.1:8080 burningyolo@104.207.67.207
```

Then open <http://localhost:8080>. The web server only listens on the VPS loopback interface; no public HTTP port needs to be opened. Close the SSH window to end the tunnel.
