# Ubuntu/Debian VPS deployment

The collector runs as a `systemd` service and stores its persistent database and match files in `/var/lib/dota-observer/data`. The installer also serves the frontend through Nginx and PHP-FPM on loopback only, so you can view it through an SSH tunnel without exposing the dashboard publicly.

## Requirements

Install PHP CLI with cURL and SQLite support:

```sh
sudo apt update
sudo apt install -y nginx php-cli php-curl php-fpm php-sqlite3
```

## Docker deployment (recommended on this VPS)

The VPS runs the collector and dashboard from `/home/burningyolo/dota-observer`. Keep the server's `.env` and `data/` directory there; they contain runtime configuration and persistent collector data and must not be replaced during code deployments. For a first install, copy the project files, create `.env` from `.env.example`, set its permissions to `600`, and start the services:

```sh
cd ~/dota-observer
cp .env.example .env
chmod 600 .env
docker compose up -d --build
```

The Compose setup runs the collector and dashboard in separate containers. Both restart after a VPS reboot. The database and match files remain in `data/` on the host. The dashboard is published on port 8181, so browse to `http://<VPS-IP>:8181`.

The settings page is at `/settings.php` and uses the password configured by `SETTINGS_PASSWORD_HASH` in the server's `.env`. The dashboard and raw match data do not require login. Restrict port 8181 in your VPS provider firewall to trusted IPs if you want private access. For private access, keep the port on loopback and use an SSH tunnel instead.

```powershell
ssh -i "$env:USERPROFILE\.ssh\id_ed25519" -N -L 8080:127.0.0.1:8181 burningyolo@104.207.67.207
```

Check the service and logs:

```sh
docker compose ps
docker compose logs -f collector
```

Stop both containers without deleting collected data with `docker compose down`.

## Automatic deployment with GitHub Actions

`.github/workflows/deploy-vps.yml` deploys each push to `main` and can also be run manually from the Actions tab. It lints PHP, runs the offline web/collector/CLI feature tests, transfers the committed files over SSH, validates the Compose configuration, and rebuilds/restarts the services. The workflow deploys only committed files, so the VPS `.env` and `data/` are not uploaded or overwritten.

Set up a dedicated SSH key for Actions:

1. Generate a key pair on your computer:

   ```powershell
   ssh-keygen -t ed25519 -C "github-actions-dota-observer" -f "$env:USERPROFILE\.ssh\dota-vps-actions" -N ""
   ```

2. Add the contents of `dota-vps-actions.pub` to `/home/burningyolo/.ssh/authorized_keys` on the VPS.
3. In the GitHub repository, open **Settings → Secrets and variables → Actions → New repository secret**. Name it `VPS_SSH_PRIVATE_KEY` and set its value to the contents of `dota-vps-actions` (the private key). Do not commit or share the private key.
4. Push a commit to `main`, or run **Deploy to VPS** from the repository's Actions tab.

The workflow pins the VPS SSH host key so it will fail safely if the server key changes. Update the pinned key in the workflow only after verifying a legitimate host-key rotation.

## Systemd deployment (requires sudo)

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
