# ItchClaim PHP

A PHP port of the [ItchClaim Python library](https://github.com/Smart123s/ItchClaim) for automatically claiming free games from itch.io.

## Installation

1. Clone this repository
2. Install dependencies with Composer:
   ```bash
   composer install
   ```

## Usage

### Basic Usage

```bash
php itchclaim.php --login <username> --dev claim
```

This command logs in the user (asks for password if it's the first time), refreshes the list of currently free games, and claims the unowned ones.

### Logging in (via command line arguments)

If you don't have access to an interactive shell, you can provide your password via command line arguments too:

```bash
php itchclaim.php --login <username> --password <password> --totp <2FA code or secret> --dev claim
```

### Using Environment Variables

If no credentials are provided via command line arguments, the script checks the following environment variables:
- `ITCH_USERNAME` (equivalent of `--login <username>` flag)
- `ITCH_PASSWORD` (equivalent of `--password <password>` flag)
- `ITCH_TOTP` (equivalent of `--totp <2FA code or secret>` flag)

### Setting Up as a Cron Job

You can set up the script to run automatically as a cron job to claim free games periodically:

1. Configure your credentials in `claim-cron.php` or set the appropriate environment variables
2. Add an entry to your crontab (run `crontab -e`):

```
# Run every 6 hours
0 */6 * * * php /path/to/claim-cron.php >> /path/to/itchclaim-log.txt 2>&1
```

## Available Commands

- `claim`: Claim all unowned free games
- `refresh_library`: Refresh the list of owned games
- `refresh_sale_cache`: Refresh the cache about game sales
- `download_urls <url>`: Get details about a game, including download URLs
- `generate_web`: Generate a static website with available games
- `version`: Display the version of the script and exit

## Options

- `--version`: Show version information
- `--login <username>`: Username or email to log in with
- `--password <password>`: Password for login
- `--totp <code>`: 2FA code or secret
- `--url <url>`: URL for game list (default: https://itchclaim.tmbpeter.com/api/active.json)
- `--games_dir <dir>`: Directory for game data
- `--web_dir <dir>`: Directory for website files
- `--max_pages <num>`: Maximum number of pages to download
- `--no_fail`: Continue even if errors occur
- `--max_not_found_pages <num>`: Maximum consecutive 404 pages before stopping
- `--dev`: Development mode (disables SSL verification)
- `--help`: Show help message

## Advanced Usage

### Refresh Library

```bash
php itchclaim.php --login <username> refresh_library
```

Allows you to refresh the locally stored list of owned games. Useful if you have claimed/purchased games since you started using the script.

### Refresh Sale Cache

```bash
php itchclaim.php refresh_sale_cache --games_dir web/data/ --max_pages -1
```

Request details about itch.io sales and save the free ones to disk.

### Download Links

```bash
php itchclaim.php --login <username> download_urls <game_url>
```

Generate a list of uploaded files and their download URLs for a game. These links have an expiration date. If the game doesn't require claiming, this command can be run without logging in.

### Generate Static Website

```bash
php itchclaim.php --login <username> generate_web --web_dir web/
```

Generates a static HTML file containing a table of all the sales cached on the disk.

## Docker Support

The application includes Docker support. Environment variables and volumes are preserved between container restarts.

## Storage Locations

- User sessions and cookies are stored in:
  - Windows: `%LOCALAPPDATA%\ItchClaim\users\`
  - Linux/macOS: `$XDG_CONFIG_HOME/itchclaim/users/` or `$HOME/.config/itchclaim/users/`
- When running in Docker, data is stored in the `/data/` volume

## Legal

This tool is not affiliated with itch.io. Using it may not be allowed, and may result in your account getting suspended or banned. Use at your own risk. 

For more information, visit [Smart123s/ItchClaim](https://github.com/Smart123s/ItchClaim#faq) and read the FAQ.

## License

Released under The MIT License (MIT).