# Quora Importer

**Contributors:** Philippe Guglielmetti, Antigravity  
**Tags:** quora, importer, import, migrate, answers  
**Requires at least:** 5.8  
**Tested up to:** 7.0  
**Stable tag:** 1.3.0  
**Requires PHP:** 7.4  
**License:** GPLv2 or later  
**License URI:** [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)  

Import your Quora blog directly into WordPress from an export ZIP archive or an index.html file.

## Description

Easily migrate your Quora answers, drafts, and space posts to WordPress. Preserves images and formatting.

## Installation

1. Upload the plugin files to the `/wp-content/plugins/quora-importer` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Tools > Import and select Quora to start importing.

## Configuration Server (Selenium & Chrome Headless)

For importing comments (either directly or via the deferred on-demand queue), the server must have Google Chrome and Selenium installed, and the web server user (typically `www-data` or `nginx`) must have write permissions to its home directory (`~/.config`, `~/.cache`) to run Google Chrome headless.

A configuration script `setup-selenium.sh` is provided in the parent directory of the plugin (or can be downloaded from the repository) to automate this setup on Debian/Ubuntu servers.

To run it:
1. Connect to your server via SSH.
2. Navigate to your WordPress plugins directory: `cd /path/to/wordpress/wp-content/plugins/quora-importer`
3. Run the setup script with root privileges: `sudo ../setup-selenium.sh`

This script will:
- Install Python 3, pip, and Google Chrome Stable.
- Install the `selenium` package.
- Determine the web server user and create necessary directories (`~/.config`, `~/.cache`, `~/.local`).
- Configure permissions so Chrome can launch in headless mode without crashing.
