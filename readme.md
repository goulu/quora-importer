# Quora Importer

## Description

Easily migrate your Quora answers, drafts, and space posts to WordPress. Preserves images and formatting.

There are 2 versions of this plugin:
* the version officially  accepted by WordPress, at https://wordpress.org/plugins/quora-importer/
* this "extended" version that can only be installed from GitHub repository (https://github.com/goulu/quora-importer) with several additional features:
    * import labels (topics) of each post
    * checks the validity of Quora URL (by fetching it)
    * import comments, using Selenium and Chrome in background


## Installation

1. Upload the plugin files to the `/wp-content/plugins/quora-importer` directory.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Tools > Import and select Quora to start importing.

## Configuration Server (Selenium & Chrome Headless)

For importing comments (either directly or via the deferred on-demand queue), the server must have Google Chrome and Selenium installed, and the web server user (typically `www-data` or `nginx`) must have write permissions to its home directory (`~/.config`, `~/.cache`) to run Google Chrome headless.

A configuration script `setup-selenium.sh` is provided in the `extended/` directory of the plugin (or can be downloaded from the repository) to automate this setup on Debian/Ubuntu servers.

To run it:
1. Connect to your server via SSH.
2. Navigate to your WordPress plugins directory: `cd /path/to/wordpress/wp-content/plugins/quora-importer`
3. Run the setup script with root privileges: `sudo extended/setup-selenium.sh`

This script will:
- Install Python 3, pip, and Google Chrome Stable.
- Install the `selenium` package.
- Determine the web server user and create necessary directories (`~/.config`, `~/.cache`, `~/.local`).
- Configure permissions so Chrome can launch in headless mode without crashing.
