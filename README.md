# Dota Observer

## Project layout

```text
public/
  assets/constants/   Local hero, item, and ability name mappings
  index.php           Collector dashboard
  data.php            Raw JSON and SQLite browser
  match.php           Parsed match research report
data/
  collector.sqlite
  live/
  final/
script.php            Live collector
status.php            CLI status report
cleanup.php           CLI cleanup utility (dry run unless --apply)
```

## View the frontend

From this project directory, run:

```powershell
php -S localhost:8000 -t public
```

Then open <http://localhost:8000>. The web pages read the sibling `data/` directory. Set `DATA_DIR` if the collector stores its files elsewhere.

The collector remains a separate CLI process:

```powershell
php script.php
```

The match report uses local name mappings from [OpenDota's dotaconstants](https://github.com/odota/dotaconstants). Icons load from Valve's Steam CDN. Opening a match report does not call the OpenDota API.
