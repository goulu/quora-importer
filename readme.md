# Quora Importer

## Description

Easily migrate your Quora answers, drafts, and space posts to WordPress. Preserves images and formatting.

There are 2 versions of this plugin:
* the version officially  accepted by WordPress, at https://wordpress.org/plugins/quora-importer/
* this "extended" version that can only be installed from GitHub repository (https://github.com/goulu/quora-importer) with several additional features:
    * import labels (topics) of each post
    * checks the validity of Quora URL (by fetching it)
    * import comments, using fast HTTP clients in background

## Installation

1. Upload the plugin files to the `/wp-content/plugins/quora-importer` directory.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Tools > Import and select Quora to start importing.

## Server Requirements

For importing comments and labels, the server requires Python 3 to be installed. The plugin uses fast HTTP clients (`cloudscraper`, `requests`, `urllib`, or `curl`) to retrieve Quora pages directly and parse comments/labels in the background. No heavy browser engines or Selenium dependencies are required.
